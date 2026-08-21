<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Controller\BasketController;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the visitor is sent when they validate their basket.
 *
 * The three ways out are pinned here: the provider's checkout when the basket can be ordered and paid for,
 * and the basket page carrying a flash when it cannot - a payment key missing, or a line that ran out while
 * the basket sat there. In both refusals the basket is left untouched, so the visitor comes back to it.
 */
class BasketControllerTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
    }

    // A basket the session no longer names sends the visitor back to the basket page rather than answering a 500
    public function testAVisitorWithoutABasketIsSentBackToTheBasketPage(): void
    {
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(null);

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
    }

    // The one way through: validate() answers the url to pay at, and the visitor is sent to it without anything else being rendered
    public function testAValidatedBasketSendsTheVisitorToTheProvider(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willReturn('https://checkout.example/session-1');

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://checkout.example/session-1', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
    }

    // A shop whose payment keys are missing takes no order rather than answering a 500, and says so once
    public function testAShopWithoutAPaymentKeyRefusesWithAFlash(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willThrowException(new PaymentUnavailableException('no key'));

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(['flash.payment_unavailable'], $this->session->getFlashBag()->get('danger'));
    }

    // The provider's own message is passed on as it is: only the bundle owning the item can say whether it ran out, was withdrawn or was taken offline
    public function testAnItemNoLongerOrderableRefusesWithTheProvidersOwnMessage(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willThrowException(new BasketNotOrderableException('Only 2 left of "Mug"'));

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(['Only 2 left of "Mug"'], $this->session->getFlashBag()->get('danger'));
    }

    // A basket, its coordinates form filled in and submitted
    private function basketService(): BasketServiceInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(new Basket());
        $basketService->method('createForm')->willReturn($form);

        return $basketService;
    }

    private function controller(BasketServiceInterface $basketService): BasketController
    {
        // The translator answers the key itself, so a flash is asserted on the key rather than on a wording
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new BasketController(
            $this->createStub(ConfigServiceInterface::class),
            $basketService,
            $this->createStub(BasketRecommendationRegistry::class),
            $translator,
        );

        $controller->setContainer($this->container());

        return $controller;
    }

    // What AbstractController reaches for on these paths: the router for redirectToRoute(), the request stack for addFlash()
    private function container(): ContainerInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name): string => 'basket_display' === $name ? '/shop/basket/display' : '/' . $name
        );

        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack([$request]);

        $services = ['router' => $router, 'request_stack' => $requestStack];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }
}
