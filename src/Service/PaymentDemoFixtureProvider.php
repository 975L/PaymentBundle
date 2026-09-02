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
use c975L\UiBundle\Contract\DemoFixtureProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a demo shop charges to post a parcel, and the only rows of this bundle a dataset has any business writing.
 *
 * A grid is settings, not history: it is what the shop decided before anyone ordered anything, and a screen showing
 * none says nothing about what a zone is or how a weight bracket reads. The orders themselves are not here - an
 * order copies what it holds, so it can only be written beside a catalogue (see ShopBundle's ShopDemoOrderLinker).
 *
 * Three zones, which is the fewest that shows what a zone is for: the country the shop is in, everything around
 * it at another price, and the catch-all that carries no country and takes whatever the first two did not. The
 * brackets are cheap-to-heavy, the last one open-ended, which is how a grid is read.
 */
class PaymentDemoFixtureProvider implements DemoFixtureProviderInterface
{
    // Written down rather than taken from the clock, a demo being reloaded often
    private const string CREATION = '2026-01-05 09:00:00';

    // Grams, the unit the grid is read in - the last bracket carries no ceiling, which is what makes it the last
    private const array HOME = [[500, 490], [2000, 690], [null, 990]];
    private const array AROUND = [[500, 990], [2000, 1490], [null, 2290]];
    private const array ELSEWHERE = [[500, 1490], [2000, 2290], [null, 3490]];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The rates ride ShippingZone's own ORM cascade, so only the zones are yielded and only the zones are recorded
    public function getDemoFixtures(): iterable
    {
        yield $this->zone('label.payment_sample_zone_home', ['FR'], self::HOME);
        yield $this->zone('label.payment_sample_zone_around', ['BE', 'CH', 'DE', 'ES', 'IT', 'LU'], self::AROUND);

        // No countries is what makes a zone the catch-all, without which an order posted anywhere else resolves no zone and ships free - and the shipping health check says so on the very screen a demo shows
        yield $this->zone('label.payment_sample_zone_elsewhere', [], self::ELSEWHERE);
    }

    /**
     * @param list<string>                     $countries
     * @param list<array{0: int|null, 1: int}> $brackets
     */
    private function zone(string $nameKey, array $countries, array $brackets): ShippingZone
    {
        $zone = new ShippingZone()
            ->setName($this->translator->trans($nameKey, [], 'payment'))
            ->setCountries($countries)
            ->setActive(true)
            ->setCreation(new \DateTime(self::CREATION))
        ;

        foreach ($brackets as [$maxWeight, $price]) {
            $zone->addRate(new ShippingRate()->setMaxWeight($maxWeight)->setPrice($price));
        }

        return $zone;
    }
}
