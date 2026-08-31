<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Repository\ShippingZoneRepository;

// What a parcel of that weight costs to that country, read off the grid a shop wrote in the back office (see ShippingZone). Nothing written is nothing charged: a shop that has posted no zone posts free, which is what it was already doing when "shop-shipping" was left empty
class ShippingRateResolver implements ShippingRateResolverInterface
{
    public function __construct(
        private readonly ShippingZoneRepository $zoneRepository,
    ) {
    }

    public function resolve(?string $country, int $weight): ?int
    {
        $zone = $this->zoneFor($country);
        if (null === $zone) {
            return null;
        }

        // Smallest tier first, so the parcel is charged at the first one it fits in rather than at whichever the database handed over first. Sorted here and not in SQL: the boundless tier is a null, and where a null sorts is the database's own idea
        $rates = $zone->getRates()->toArray();
        usort($rates, static fn (ShippingRate $a, ShippingRate $b): int => match (true) {
            null === $a->getMaxWeight() => 1,
            null === $b->getMaxWeight() => -1,
            default => $a->getMaxWeight() <=> $b->getMaxWeight(),
        });

        foreach ($rates as $rate) {
            if ($rate->covers($weight)) {
                return $rate->getPrice();
            }
        }

        // A zone whose tiers all stop short of this parcel charges nothing rather than the heaviest tier it has: a shop that has not said what a 10 kg parcel costs has not said it costs the price of a 5 kg one
        return null;
    }

    public function cheapest(): ?int
    {
        $prices = [];

        foreach ($this->zoneRepository->findActive() as $zone) {
            foreach ($zone->getRates() as $rate) {
                $prices[] = $rate->getPrice();
            }
        }

        return [] === $prices ? null : min($prices);
    }

    // The zone that names this country, failing which the one naming none - the catch-all a shop writes when it posts everywhere at one tariff
    private function zoneFor(?string $country): ?ShippingZone
    {
        $catchAll = null;

        foreach ($this->zoneRepository->findActive() as $zone) {
            if ($zone->holdsCountry($country)) {
                return $zone;
            }

            // The first one found and no arbitration: two catch-all zones is a shop's own mistake, and the health check names it rather than this picking a winner in silence
            $catchAll ??= $zone->isCatchAll() ? $zone : null;
        }

        return $catchAll;
    }
}
