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
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the three basket calls answer to a body they cannot read.
 *
 * They are public routes, so anything at all reaches them: an empty POST, a truncated payload, a kind no provider
 * answers for. Each is the caller's error and is answered as one - the site used to crash on the read instead, and
 * the crash came late enough to leave an empty basket behind it.
 */
class BasketMalformedRequestTest extends TestCase
{
    // The route is a POST like any other: crawlers and probes reach it with nothing in hand
    public function testAnEmptyBodyIsRefusedRatherThanCrashing(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service(null)->addItem(new Request());
    }

    // A body read but incomplete: the three keys are what the basket is asked for
    public function testABodyMissingAKeyIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service(null)->addItem($this->jsonRequest(['id' => 7, 'quantity' => 1]));
    }

    // A kind nothing answers for used to reach the registry, which threw the exception a crash is made of
    public function testAKindNoProviderAnswersForIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service(null, false)->addItem($this->jsonRequest(['type' => 'unknown', 'id' => 7, 'quantity' => 1]));
    }

    // What the refusal is really about: the body is read first, so nothing is written before it is found wanting
    public function testARefusedCallCreatesNoBasket(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->service(null, true, $entityManager)->addItem(new Request());
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    // The same body reaches the deletion, on a basket the session does name
    public function testDeletingWithAnEmptyBodyIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service($this->basket())->deleteItem(new Request());
    }

    // The third call reads a body too, and answers an unreadable one the same way
    public function testApplyingACodeWithAnEmptyBodyIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service($this->basket())->applyCode(new Request());
    }

    // A body that reads but carries no code is not malformed: it is a code the shop does not know, which the customer is told about
    public function testABodyCarryingNoCodeIsAnUnknownCodeRatherThanABadRequest(): void
    {
        $answer = $this->service($this->basket())->applyCode($this->jsonRequest([]));

        $this->assertArrayHasKey('error', $answer);
    }

    private function basket(): Basket
    {
        $basket = new Basket();
        $basket->setStatus('new');
        $basket->setCurrency('EUR');
        $basket->setTotal(0);
        $basket->setShipping(0);
        $basket->setQuantity(0);
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setItems([]);

        return $basket;
    }

    private function jsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function service(?Basket $basket, bool $known = true, ?EntityManagerInterface $entityManager = null): BasketService
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('find')->willReturn($basket);

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($this->createStub(BasketItemProviderInterface::class));
        $itemProviderRegistry->method('has')->willReturn($known);

        $session = new Session(new MockArraySessionStorage());
        if (null !== $basket) {
            $session->set('basket', 1);
        }
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        return new BasketService(
            $basketRepository,
            $configService,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
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
            $this->createStub(ShippingRateResolverInterface::class),
        );
    }
}
