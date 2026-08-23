<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\EventSubscriber;

use c975L\PaymentBundle\Repository\BasketRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Gives a customer the orders they placed before they had an account, the first time they sign in.
 *
 * A shop takes orders from visitors who never opened an account: those baskets carry the address typed at the
 * checkout and nothing else. The day that address signs in, they become theirs - which is what makes the
 * invitation the order confirmation carries worth following, and what fills the customer area of somebody who
 * bought for years without ever registering.
 *
 * On the login and not on the sign-up, deliberately: it then works for an account opened through a provider, one
 * opened at the registration form, and one that predates all of this - a single place rather than one per door.
 * Running it on every login rather than only on the first costs an indexed update that matches nothing once the
 * orders have been claimed, and needs no "already done" flag to be kept anywhere.
 *
 * The address has to have been proved for any of this to be safe, which is what isEnabled() says: an account is
 * only ever enabled by EmailVerifier, once its owner followed the link sent to that address, or by an OAuth
 * sign-in, where the provider vouched for it. Matching on an address nobody proved would hand a stranger's
 * orders - delivery address included - to whoever registered with their email.
 */
class BasketAccountLinkSubscriber implements EventSubscriberInterface
{
    // The logger is optional: an app without Monolog leaves it null and everything else works the same
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        // isEnabled() is the app's own (see ConfigBundle's scaffolded User), declared by no Symfony interface - an entity without it is read as proved, since nothing refuses its login either
        if (method_exists($user, 'isEnabled') && true !== $user->isEnabled()) {
            return;
        }

        $attached = $this->basketRepository->attachOrphansTo($user, $user->getUserIdentifier());

        if ($attached > 0) {
            $this->logger?->info(sprintf('%d order(s) placed as a guest were attached to "%s" on login.', $attached, $user->getUserIdentifier()));
        }
    }
}
