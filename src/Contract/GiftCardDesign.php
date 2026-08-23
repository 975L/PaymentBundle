<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

/**
 * What a card looks like, handed over by whichever bundle sold it (see ShopBundle's ProductBasketItemProvider).
 *
 * A value and not an entity: the visual belongs to that bundle's catalogue, and this one copies it onto the card
 * at issuance rather than pointing at it (see GiftCardService::issue()) - a design withdrawn from sale next month
 * must not blank a card somebody still holds.
 */
readonly class GiftCardDesign
{
    public function __construct(
        // The picture of the recto, as an asset path the page can render as it stands - the very string the basket already carries for the article it was sold as
        public ?string $image = null,
        // The words printed beside the picture and the amount, i.e. what makes it a birthday card rather than a voucher
        public ?string $text = null,
        // Whether the code is hidden under a panel to be scratched off. Not decoration alone: scratched, the code is not written in the page at all and is asked for only once the panel is rubbed off (see GiftCardController::reveal())
        public bool $scratch = true,
    ) {
    }
}
