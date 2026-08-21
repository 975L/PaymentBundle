<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// What a basket becomes once it is priced and ready to be charged, in terms no payment provider owns: BasketService builds it, and each PaymentGatewayInterface maps it to its own checkout API. Amounts are in the currency's smallest unit (cents), as they are stored on the basket
final readonly class CheckoutRequest
{
    /**
     * @param list<array{name: string, amount: int, quantity: int}> $lines    one entry per basket line, plus one for the shipping when there is any
     * @param array<string, string>                                 $metadata what the provider must hand back with its notification, the basket id and the order number
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $email,
        public array $metadata,
    ) {
    }
}
