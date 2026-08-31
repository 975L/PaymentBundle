<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Message\ItemsShippedMessage;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What happens after the money: an order is shipped, in one go or in two.
 *
 * A basket holding both a physical product and a crowdfunding counterpart is shipped by two different people
 * at two different moments, so the status only moves to "shipped" once both have gone out - a basket holding
 * one kind moves on the first. Each call is announced by a message, which is what sends the customer's email.
 */
class BasketShippingTest extends TestCase
{
    private ?Envelope $dispatched = null;

    // The basket is looked up on its order number, the one written on the packing slip - never on an id nobody outside the database knows
    public function testAnOrderIsFoundOnTheNumberItIsShippedUnder(): void
    {
        $basket = $this->basket(['product' => [1 => []]]);
        $repository = $this->repository('SHOP-1', $basket);

        $this->assertSame($basket, $this->service($repository)->itemsShipped('SHOP-1', 'product'));
    }

    // A number nobody answers to is an error, not a silent no-op: it means the packing slip and the database disagree
    public function testAnUnknownNumberIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Basket not found');

        $this->service($this->repository('SHOP-1', null))->itemsShipped('SHOP-404', 'product');
    }

    // One kind of item in the basket, so shipping it ships the whole order
    public function testAnOrderOfOneKindIsShippedOutright(): void
    {
        $basket = $this->basket(['product' => [1 => []]]);

        $this->service($this->repository('SHOP-1', $basket))->itemsShipped('SHOP-1', 'product');

        $this->assertNotNull($basket->getItemsShipped());
        $this->assertSame('shipped', $basket->getStatus());
    }

    // Two kinds, shipped by two hands: the first one moves nothing but its own date
    public function testAnOrderOfTwoKindsWaitsForTheSecondOne(): void
    {
        $basket = $this->basket(['product' => [1 => []], 'crowdfunding' => [1 => []]]);
        $service = $this->service($this->repository('SHOP-1', $basket));

        $service->itemsShipped('SHOP-1', 'product');

        $this->assertNotNull($basket->getItemsShipped());
        $this->assertNull($basket->getCounterpartsShipped());
        $this->assertSame('paid', $basket->getStatus());

        $service->itemsShipped('SHOP-1', 'crowdfunding');

        $this->assertNotNull($basket->getCounterpartsShipped());
        $this->assertSame('shipped', $basket->getStatus());
    }

    // The message is what sends the customer their "it is on its way" email, so it goes out on every shipment
    public function testEachShipmentIsAnnounced(): void
    {
        $basket = $this->basket(['product' => [1 => []]]);

        $this->service($this->repository('SHOP-1', $basket))->itemsShipped('SHOP-1', 'product');

        $this->assertInstanceOf(ItemsShippedMessage::class, $this->dispatched?->getMessage());
    }

    // An order already shipped is left alone: telling the customer twice is worse than not telling them again
    public function testAnOrderAlreadyShippedIsNotAnnouncedAgain(): void
    {
        $basket = $this->basket(['product' => [1 => []]]);
        $basket->setStatus('shipped');

        $this->service($this->repository('SHOP-1', $basket))->itemsShipped('SHOP-1', 'product');

        $this->assertNull($this->dispatched);
        $this->assertNull($basket->getItemsShipped());
    }

    // The service hands the form over to the factory rather than building one, which is what lets a site override the type
    public function testTheFormIsBuiltByTheFactory(): void
    {
        $basket = $this->basket([]);
        $form = $this->createStub(FormInterface::class);

        $factory = $this->createMock(PaymentFormFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with('coordinates', $basket)
            ->willReturn($form)
        ;

        $this->assertSame($form, $this->service($this->repository('SHOP-1', $basket), $factory)->createForm('coordinates', $basket));
    }

    /**
     * @param array<string, mixed> $items
     */
    private function basket(array $items): Basket
    {
        $basket = new Basket()
            ->setNumber('SHOP-1')
            ->setStatus('paid')
            ->setItems($items)
        ;

        // The message carries the row's id, which only a persisted basket holds
        new \ReflectionProperty(Basket::class, 'id')->setValue($basket, 42);

        return $basket;
    }

    private function repository(string $number, ?Basket $basket): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Basket => ($criteria['number'] ?? null) === $number ? $basket : null
        );

        return $repository;
    }

    private function service(BasketRepository $repository, ?PaymentFormFactoryInterface $factory = null): BasketService
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(fn (object $message): Envelope => $this->dispatched = new Envelope($message));

        return new BasketService(
            $repository,
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $factory ?? $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $messageBus,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $this->createStub(BasketItemProviderRegistry::class),
            $this->createStub(PaymentGatewayRegistry::class),
            $this->createStub(PaymentTestModeInterface::class),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($this->createStub(BasketItemProviderRegistry::class)),
            $this->createStub(InvoiceService::class),
            $this->createStub(ShippingRateResolverInterface::class),
        );
    }
}
