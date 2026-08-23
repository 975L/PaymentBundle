<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// What a shopkeeper types in to be paid for something the catalogue does not sell - a deposit, a repair, an invoice settled online. Not an entity: a payment link is charged once and its basket line is the only trace it needs, where a row would be a second catalogue to keep
final readonly class PaymentLinkItem
{
    // The line every basket entry of this kind is filed under. There is exactly one of them per link, so it is a constant rather than an identifier drawn from anywhere
    public const int ID = 1;

    // Amount in the currency's smallest unit (cents), VAT included, as every other amount of a basket is held
    public function __construct(
        public string $label,
        public int $amount,
        public ?string $description = null,
    ) {
    }
}
