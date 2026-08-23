<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\EventSubscriber;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\EventSubscriber\BasketRecoverySubscriber;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// A session lasts 24 minutes, a basket 14 days: what gets one back to the visitor the session forgot
class BasketRecoverySubscriberTest extends TestCase
{
    private const string TOKEN = 'aaaabbbbccccdddd';

    // The visitor came back after their session was recycled, and their basket is theirs again
    public function testTheCookieSeatsTheBasketBackInTheSession(): void
    {
        $basket = $this->basket();
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findRecoverable')->willReturn($basket);

        $request = $this->request(self::TOKEN);
        $this->subscriber($repository)->onKernelRequest($this->requestEvent($request));

        $this->assertSame(42, $request->getSession()->get('basket'));
    }

    // A cookie surviving a logout on a shared computer opens nothing: that basket belongs to somebody
    public function testABasketOfSomebodyElseIsNotHandedOver(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findRecoverable')->willReturn($this->basket($this->createStub(UserInterface::class)));

        $request = $this->request(self::TOKEN);
        $this->subscriber($repository)->onKernelRequest($this->requestEvent($request));

        $this->assertNull($request->getSession()->get('basket'));
    }

    // The customer filled a basket on their laptop and logs in on their phone, where no cookie of theirs has ever been
    public function testTheAccountCarriesTheBasketToAnotherBrowser(): void
    {
        $user = $this->createStub(UserInterface::class);
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findLastOpenByUser')->willReturn($this->basket($user));

        $request = $this->request();
        $this->subscriber($repository, $this->tokenStorage($user))->onKernelRequest($this->requestEvent($request));

        $this->assertSame(42, $request->getSession()->get('basket'));
    }

    // Nothing to recover must cost nothing: reading the session here would start one, i.e. hand a cookie to every anonymous page
    public function testAVisitorWithNothingToRecoverStartsNoSession(): void
    {
        $repository = $this->createMock(BasketRepository::class);
        $repository->expects($this->never())->method('findRecoverable');

        $request = $this->request();
        $this->subscriber($repository)->onKernelRequest($this->requestEvent($request));

        $this->assertFalse($request->getSession()->isStarted());
    }

    // The session is the one that names the basket, and a live one is never second-guessed
    public function testALiveSessionIsLeftAlone(): void
    {
        $repository = $this->createMock(BasketRepository::class);
        $repository->expects($this->never())->method('findRecoverable');

        $request = $this->request(self::TOKEN);
        $request->getSession()->set('basket', 7);
        $this->subscriber($repository)->onKernelRequest($this->requestEvent($request));

        $this->assertSame(7, $request->getSession()->get('basket'));
    }

    // What makes the next session recoverable, posed on the way out
    public function testAnOpenBasketPosesTheCookie(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn($this->basket());

        $request = $this->request();
        $request->getSession()->set('basket', 42);
        $response = new Response();
        $this->subscriber($repository)->onKernelResponse($this->responseEvent($request, $response));

        $cookie = $this->cookieOf($response);
        $this->assertNotNull($cookie);
        $this->assertSame(self::TOKEN, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
    }

    // The order went through, or the customer emptied the basket: the cookie goes with it rather than naming what is no longer theirs
    public function testAFinishedBasketTakesTheCookieAway(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn($this->basket()->setStatus('paid'));

        $request = $this->request(self::TOKEN);
        $request->getSession()->set('basket', 42);
        $response = new Response();
        $this->subscriber($repository)->onKernelResponse($this->responseEvent($request, $response));

        $cookie = $this->cookieOf($response);
        $this->assertNotNull($cookie);
        $this->assertSame('', (string) $cookie->getValue());
    }

    // A request carrying no session - the payment provider's webhook - had no chance to recover anything, so it says nothing about the cookie either
    public function testARequestWithoutSessionTouchesNoCookie(): void
    {
        $repository = $this->createMock(BasketRepository::class);
        $repository->expects($this->never())->method('find');

        $request = new Request();
        $request->cookies->set(BasketRecoverySubscriber::COOKIE_NAME, self::TOKEN);
        $response = new Response();
        $this->subscriber($repository)->onKernelResponse($this->responseEvent($request, $response));

        $this->assertSame([], $response->headers->getCookies());
    }

    private function basket(?UserInterface $user = null): Basket
    {
        $basket = new Basket()
            ->setStatus('new')
            ->setRecoveryToken(self::TOKEN)
            ->setUser($user)
        ;

        // The id is generated by the database, which no unit test has
        $reflection = new \ReflectionProperty(Basket::class, 'id');
        $reflection->setValue($basket, 42);

        return $basket;
    }

    private function subscriber(BasketRepository $repository, ?TokenStorageInterface $tokenStorage = null): BasketRecoverySubscriber
    {
        return new BasketRecoverySubscriber($repository, $tokenStorage ?? new TokenStorage());
    }

    private function tokenStorage(UserInterface $user): TokenStorageInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        return $tokenStorage;
    }

    private function request(?string $cookie = null): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        if (null !== $cookie) {
            $request->cookies->set(BasketRecoverySubscriber::COOKIE_NAME, $cookie);
        }

        return $request;
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function cookieOf(Response $response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (BasketRecoverySubscriber::COOKIE_NAME === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
