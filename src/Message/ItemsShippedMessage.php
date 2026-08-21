<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Message;

class ItemsShippedMessage
{
    public function __construct(
        private readonly int $basketId,
        private readonly string $type,
    ) {
    }

    public function getBasketId(): int
    {
        return $this->basketId;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
