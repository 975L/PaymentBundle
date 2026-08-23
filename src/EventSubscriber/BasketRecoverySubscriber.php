<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\EventSubscriber;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketRetentionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Gets a visitor their basket back once their session has gone.
 *
 * A basket is named by the session and by nothing else, while PHP recycles a session after 24 minutes of
 * inactivity by default: a visitor who filled a basket, left the tab open over lunch and came back to it found
 * it empty, and the row stayed in the database until the nightly purge - which is what fills the back-office
 * basket list with baskets nobody abandoned on purpose.
 *
 * Two ways back, tried in that order: the recovery cookie the browser keeps, which is the only one an anonymous
 * visitor has, then the basket the customer's own account carries, which follows them from one device to the next.
 * Both only ever re-seat the basket's id in the session, so BasketService goes on reading the session and nothing
 * else knows a recovery took place.
 *
 * The cookie itself is written on the way out, mirroring what the session holds: an open basket poses it, anything
 * else - the basket ordered, deleted, or gone from the database - takes it away. Nothing is remembered between the
 * two passes, which is what keeps a lost session ("no basket, but the row is still there") apart from a finished
 * one ("no basket, and the row says why").
 */
class BasketRecoverySubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'basket_recovery';

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    // Default priority on the request, so the firewall (8) has named the user by the time the account is asked for a basket
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    // Re-seats the visitor's basket in the session when it names none
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $token = $request->cookies->get(self::COOKIE_NAME);
        $user = $this->getUser();

        // Asked before the session is touched: a visitor holding neither cookie nor account has nothing to recover, and reading the session for the sake of finding that out would start one - i.e. hand a session cookie to every anonymous page and take it out of any cache
        if (null === $token && null === $user) {
            return;
        }

        $session = $request->getSession();
        if (null !== $session->get('basket')) {
            return;
        }

        $basket = $this->findByCookie($token, $user) ?? $this->findByUser($user);
        if (null !== $basket) {
            $session->set('basket', $basket->getId());
        }
    }

    // Writes the recovery cookie of the basket the session holds, or takes it away
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // A request with no session - the payment provider's webhook - had no chance to recover anything, so it is in no position to say a cookie is stale either
        if (!$request->hasSession()) {
            return;
        }

        $basket = $this->getSessionBasket($request);
        $token = $request->cookies->get(self::COOKIE_NAME);

        if (null !== $basket && 'new' === $basket->getStatus() && null !== $basket->getRecoveryToken()) {
            // Posed again on every response rather than only when it changes: the cookie then lives as long from the visitor's last page as the basket does from its last change, and a shopper coming back every week never has it expire under them
            $event->getResponse()->headers->setCookie($this->createCookie($request, $basket->getRecoveryToken()));

            return;
        }

        // No open basket left: the order went through, the customer emptied it, or the purge took the row away - and the cookie goes with it rather than naming something that is no longer theirs to come back to
        if (null !== $token) {
            $event->getResponse()->headers->clearCookie(self::COOKIE_NAME, '/', null, $request->isSecure(), true, Cookie::SAMESITE_LAX);
        }
    }

    // The basket the cookie names, if it is one this visitor may be handed
    private function findByCookie(?string $token, ?UserInterface $user): ?Basket
    {
        if (null === $token) {
            return null;
        }

        $basket = $this->basketRepository->findRecoverable($token);

        // A basket already belonging to somebody is given back to them and to nobody else: a cookie surviving a logout on a shared computer would otherwise open the previous customer's basket, with what they mean to buy in it
        if (null === $basket || (null !== $basket->getUser() && $basket->getUser() !== $user)) {
            return null;
        }

        return $basket;
    }

    // The basket the customer's account carries, for a cookie cleared or a browser they have never shopped on
    private function findByUser(?UserInterface $user): ?Basket
    {
        return null === $user ? null : $this->basketRepository->findLastOpenByUser($user);
    }

    // The basket the session names as this request leaves, read from the database so a row removed along the way reads as gone
    private function getSessionBasket(Request $request): ?Basket
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return null;
        }

        $id = $session->get('basket');

        return null === $id ? null : $this->basketRepository->find($id);
    }

    // Kept out of JavaScript's reach and, by Symfony's own default, sent on same-site requests only: it names a basket carrying what the customer is about to buy
    private function createCookie(Request $request, string $token): Cookie
    {
        // Kept for the very window the purge works to, so the visitor is never sent back to a basket it has already taken away
        $expires = new \DateTime('+' . BasketRetentionService::UNVALIDATED_DAYS . ' days');

        return new Cookie(
            name: self::COOKIE_NAME,
            value: $token,
            expire: $expires,
            secure: $request->isSecure(),
            httpOnly: true,
        );
    }

    // Gets user
    private function getUser(): ?UserInterface
    {
        $token = $this->tokenStorage->getToken();

        return $token?->getUser();
    }
}
