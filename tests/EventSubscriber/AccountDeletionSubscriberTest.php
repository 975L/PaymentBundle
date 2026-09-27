<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\EventSubscriber;

use c975L\ConfigBundle\Contract\InactivityAwareInterface;
use c975L\ConfigBundle\Event\UserAnonymizedEvent;
use c975L\PaymentBundle\EventSubscriber\AccountDeletionSubscriber;
use c975L\PaymentBundle\Service\BasketRetentionService;
use PHPUnit\Framework\TestCase;

class AccountDeletionSubscriberTest extends TestCase
{
    // The anonymized account is the one whose never-validated baskets go
    public function testAnAnonymizedAccountHasItsOpenBasketsDeleted(): void
    {
        $user = $this->createStub(InactivityAwareInterface::class);

        $service = $this->createMock(BasketRetentionService::class);
        $service->expects($this->once())->method('deleteUnpaidOf')->with($user)->willReturn(1);

        new AccountDeletionSubscriber($service)->onUserAnonymized(new UserAnonymizedEvent($user));
    }

    // Listening to the event ConfigBundle dispatches, from the account page and from the inactivity cleanup alike
    public function testItListensToTheAnonymization(): void
    {
        $this->assertArrayHasKey(UserAnonymizedEvent::class, AccountDeletionSubscriber::getSubscribedEvents());
    }
}
