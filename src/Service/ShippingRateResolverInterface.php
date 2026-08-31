<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

interface ShippingRateResolverInterface
{
    /**
     * What the shop charges to post a parcel of $weight grams to $country, in cents.
     *
     * Null when the grid says nothing about it - no zone at all, no zone naming that country and no catch-all, or
     * a zone whose tiers all stop short of that weight. **Null is not zero to the caller's eye**, even though
     * nothing is what gets charged: it is the grid saying it has no answer, which is what the health check reports
     * and what an admin fills in.
     *
     * @param string|null $country ISO 3166-1 alpha-2, as the order carries it - null before the address is given
     * @param int         $weight  grams, the articles added up, zero on a basket nothing was weighed for - the
     *                             packaging is not counted here and never will be, a tier written for the parcel
     *                             the carrier weighs holding it already
     */
    public function resolve(?string $country, int $weight): ?int;

    /**
     * The lowest price the grid holds, whatever the zone and the weight - what a page states delivery starts at,
     * a grid having no single rate to name any more.
     *
     * Null when the grid holds no tier at all, which reads as "this shop says nothing about delivery" and not as
     * "delivery is free": the block that states it stays silent rather than promising something nobody wrote.
     */
    public function cheapest(): ?int;
}
