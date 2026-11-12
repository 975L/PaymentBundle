<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use c975L\PaymentBundle\Entity\Basket;

// Implemented, on top of BasketItemProviderInterface, by a provider whose onBasketPaid() wrote something of its own that a full refund must take back - credits, a stock, an edition copy. Optional: a provider whose access is read off the order status alone needs nothing more
interface RefundableBasketItemProviderInterface extends BasketItemProviderInterface
{
    // Called once the order, delivered, is refunded in full or a dispute lost, its status already Refunded and the credit note drawn - never on a partial refund, never twice, from the webhook so nothing is read off the request; an exception is logged and the other providers are still told
    /** @param array<int|string, array<string, mixed>> $itemsOfThisKind this provider's own basket entries, keyed by item id */
    public function onBasketRefunded(Basket $basket, array $itemsOfThisKind): void;
}
