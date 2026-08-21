<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// A payment the provider confirms, reduced to what the site records of it - the gateway has already verified the signature and read the provider's own event shape, so BasketService never sees either
// $amount is what the provider actually charged, in the currency's smallest unit: BasketService checks it against the amount the basket was numbered for, and a gateway that cannot report it leaves it null
final readonly class PaymentNotification
{
    public function __construct(
        public string $basketId,
        public string $gateway,
        public ?string $transactionId = null,
        public ?string $paymentMethod = null,
        public ?int $amount = null,
    ) {
    }
}
