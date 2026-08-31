<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Implemented, on top of BasketItemProviderInterface, by a provider whose lines carry a shipping weight. Kept apart on purpose, like ExpirableGatewayInterface and its siblings: a provider selling nothing that ships stays valid without it, and so does one whose catalogue does not weigh its articles yet
// shop-shipping is a single flat rate, which holds on a homogeneous catalogue and nowhere beyond: a carrier prices a parcel by weight and by zone. The weight is a fact of the article, so it is the selling bundle that knows it; the tariff grid and the zones belong here, with whoever takes the money and posts the parcel
interface WeighableBasketItemProviderInterface
{
    /**
     * The shipping weight of one basket line, in grams, quantity included - three articles of 400 g weigh 1200.
     *
     * Grams, whole, as prices are held in cents: a tenth of a gram changes no tariff, and a float would have the
     * sum of a basket drift from what its lines read.
     *
     * Null when the line contributes nothing to weigh - a download, a service, a gift card sent by e-mail - and
     * null again for a line whose article carries no weight, which the caller adds up as nothing rather than as
     * zero: a catalogue half weighed would otherwise price a parcel as if the rest of it were feathers.
     *
     * Read the entry with defaults rather than as a guaranteed shape: an order's items are a snapshot frozen the
     * day it was placed, and one taken before this bundle weighed anything carries no such key at all - a
     * years-old order still has to be displayed, e-mailed and reprinted (see getContentFlags()).
     *
     * @param array<string, mixed> $itemData one entry as toBasketData() built it
     */
    public function getWeight(array $itemData): ?int;
}
