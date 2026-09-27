<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\EventSubscriber;

use c975L\ConfigBundle\Event\UserAnonymizedEvent;
use c975L\PaymentBundle\Service\BasketRetentionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// An account anonymized, by its owner or by the inactivity cleanup, takes its never-validated baskets with it: a validated one waits for the nightly pass, the paid ones stay, nominative, for the accounting retention
class AccountDeletionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BasketRetentionService $basketRetentionService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserAnonymizedEvent::class => 'onUserAnonymized',
        ];
    }

    // Deletes what the account never validated
    public function onUserAnonymized(UserAnonymizedEvent $event): void
    {
        $this->basketRetentionService->deleteUnpaidOf($event->user);
    }
}
