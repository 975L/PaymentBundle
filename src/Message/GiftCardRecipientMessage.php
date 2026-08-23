<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Message;

// Tells whoever a gift card was bought for that it is waiting for them - dispatched beside the buyer's own confirmation, and handled apart so one failing send never holds the other back
class GiftCardRecipientMessage
{
    public function __construct(
        private readonly int $basketId,
    ) {
    }

    public function getBasketId(): int
    {
        return $this->basketId;
    }
}
