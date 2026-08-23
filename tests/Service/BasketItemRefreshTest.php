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
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a basket says an article costs, against what the catalogue says today.
 *
 * A basket sits for days: the line is read again off its provider on every change, so the customer is charged the
 * price they are being shown. The snapshot only stops moving when the order is numbered.
 */
class BasketItemRefreshTest extends TestCase
{
    // The catalogue's price, read again rather than kept from the evening the basket was filled
    public function testALineIsReadAgainFromItsProvider(): void
    {
        $basket = $this->basketHolding(['quantity' => 1, 'total' => 1000, 'totalVat' => 0, 'type' => 'product', 'item' => ['id' => 7, 'title' => 'Yesterday', 'price' => 1000, 'vat' => 0.0], 'parent' => []]);

        // The shopkeeper has since raised the price and renamed the article
        $service = $this->service($basket, new RefreshableItem(7, 1200, 'Today'));
        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => 1]));

        $line = $basket->getItems()['product'][7];
        $this->assertSame('Today', $line['item']['title']);
        $this->assertSame(1200, $line['item']['price']);
        // The unit price and the total say the same thing, which is what the half-frozen line could not do
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(2400, $line['total']);
        $this->assertSame(2400, $basket->getTotal());
    }

    // Whatever a provider keeps beside the documented shape survives a refresh that knows nothing about it
    public function testWhatAProviderKeepsBesideTheLineIsNotLost(): void
    {
        $basket = $this->basketHolding(['quantity' => 1, 'total' => 1000, 'totalVat' => 0, 'type' => 'product', 'item' => ['id' => 7, 'title' => 'Yesterday', 'price' => 1000, 'vat' => 0.0], 'parent' => [], 'engraving' => 'For Camille']);

        $service = $this->service($basket, new RefreshableItem(7, 1200, 'Today'));
        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => 1]));

        $this->assertSame('For Camille', $basket->getItems()['product'][7]['engraving']);
    }

    // A payment link is minted once and never found again: its line is left exactly as it stands rather than dropped
    public function testALineWhoseProviderResolvesNothingIsKept(): void
    {
        $line = ['quantity' => 1, 'total' => 4500, 'totalVat' => 0, 'type' => 'payment_link', 'item' => ['id' => 'link', 'title' => 'Consultation', 'price' => 4500, 'vat' => 0.0], 'parent' => []];
        $basket = $this->basketHolding($line, 'payment_link', 'link');

        // Nothing to resolve, and nothing to say about it either - availability is validateCheckout()'s job
        $service = $this->service($basket, null);
        $service->deleteItem($this->jsonRequest(['type' => 'payment_link', 'id' => 'nothing-of-the-sort']));

        $kept = $basket->getItems()['payment_link']['link'];
        $this->assertSame('Consultation', $kept['item']['title']);
        $this->assertSame(4500, $kept['item']['price']);
        $this->assertSame(4500, $kept['total']);
        $this->assertSame(4500, $basket->getTotal());
    }

    // An order's lines are what was sold, and what its invoice still reads years later
    public function testAnOrderIsNeverReadAgain(): void
    {
        $line = ['quantity' => 1, 'total' => 1000, 'totalVat' => 0, 'type' => 'product', 'item' => ['id' => 7, 'title' => 'Yesterday', 'price' => 1000, 'vat' => 0.0], 'parent' => []];
        $basket = $this->basketHolding($line);
        $basket->setStatus('paid');

        $service = $this->service($basket, new RefreshableItem(7, 1200, 'Today'));
        // Asked from outside the basket's own paths, which is the only way an order ever reaches this
        $service->getJson();
        $service->updateTotals();

        $sold = $basket->getItems()['product'][7];
        $this->assertSame('Yesterday', $sold['item']['title']);
        $this->assertSame(1000, $sold['item']['price']);
        $this->assertSame(1000, $sold['total']);
    }

    private function basketHolding(array $line, string $kind = 'product', int | string $id = 7): Basket
    {
        $basket = new Basket();
        $basket->setStatus('new');
        $basket->setCurrency('EUR');
        $basket->setTotal($line['total']);
        $basket->setShipping(0);
        $basket->setQuantity($line['quantity']);
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setItems([$kind => [$id => $line]]);

        return $basket;
    }

    private function jsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function service(Basket $basket, ?RefreshableItem $item): BasketService
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('find')->willReturn($basket);

        // The one place the line's shape is written, asked again on every change of the basket
        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('findItem')->willReturn($item);
        $provider->method('validateAddition')->willReturn(null);
        $provider->method('getContentFlags')->willReturn(0);
        $provider->method('toBasketData')->willReturnCallback(fn (object $found, int $quantity): array => [
            'item' => ['id' => $found->getId(), 'title' => $found->title, 'price' => $found->getPrice(), 'vat' => 0.0],
            'parent' => [],
            'type' => 'product',
            'quantity' => $quantity,
            'totalVat' => 0,
            'total' => $found->getPrice() * $quantity,
        ]);

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);

        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', 1);
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        return new BasketService(
            $basketRepository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            $requestStack,
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $this->createStub(PaymentGatewayRegistry::class),
            $this->createStub(PaymentTestModeInterface::class),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($itemProviderRegistry),
            $this->createStub(InvoiceService::class),
        );
    }
}

// A catalogue entry as the refresh reads it: an id and a price, which is all BasketService ever asks an item for
class RefreshableItem
{
    public function __construct(
        private readonly int | string $id,
        private readonly int $price,
        public readonly string $title,
    ) {
    }

    public function getId(): int | string
    {
        return $this->id;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}
