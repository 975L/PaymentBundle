<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use c975L\PaymentBundle\Enum\PaymentEvent;

// What a store says about one purchase, reduced to what the site records of it. $accountToken is the one the app set when buying (see InAppPurchaseService::accountToken()), null when none was; $productId is empty on a Google refund notification, which only names the order; $purchaseToken is what Google acknowledges a purchase by, null at Apple
final readonly class InAppTransaction
{
    public function __construct(
        public string $store,
        public string $productId,
        public string $transactionId,
        public ?string $accountToken = null,
        public string $environment = 'production',
        public PaymentEvent $event = PaymentEvent::Paid,
        public ?string $purchaseToken = null,
        public bool $acknowledged = true,
    ) {
    }
}
