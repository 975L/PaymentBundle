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

// What a provider says about a payment - paid, refunded or charged back - reduced to what the site records of it. $amount is what the provider charged in the currency's smallest unit (on a refund the total handed back so far, on a lost chargeback what the bank took back), null when the gateway cannot report it; $basketId is null on a refund or a chargeback, the basket being found back from $transactionId
final readonly class PaymentNotification
{
    public function __construct(
        public ?string $basketId,
        public string $gateway,
        public ?string $transactionId = null,
        public ?string $paymentMethod = null,
        public ?int $amount = null,
        public PaymentEvent $event = PaymentEvent::Paid,
    ) {
    }
}
