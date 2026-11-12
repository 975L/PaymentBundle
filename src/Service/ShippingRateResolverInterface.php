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

    // Whether the order is refused on the grid: a country no zone names on a grid that names some (see shipsTo()), or a zone none of whose tiers takes a parcel that heavy - no grid at all, or a zone with no tier, is never exceeded
    public function exceeds(?string $country, int $weight): bool;

    // Whether the grid posts to this country: always with no grid written at all or no country given yet, otherwise only through a zone naming it or a catch-all
    public function shipsTo(?string $country): bool;

    // The lowest price above zero the grid holds, whatever the zone and the weight, what a page states delivery starts at - zero only when every tier is free, a free first tier beside paid ones being no free delivery, and null on a grid with no tier at all, so the block says nothing rather than promising free delivery
    public function cheapest(): ?int;
}
