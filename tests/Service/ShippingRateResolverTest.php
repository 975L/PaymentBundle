<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Repository\ShippingZoneRepository;
use c975L\PaymentBundle\Service\ShippingRateResolver;
use PHPUnit\Framework\TestCase;

// What a parcel costs to where it goes, read off the grid a shop wrote by hand - and nothing at all where it wrote nothing
class ShippingRateResolverTest extends TestCase
{
    public function testAParcelIsChargedAtTheFirstTierItFitsIn(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['FR'], [[1000, 490], [5000, 790], [null, 1290]])]);

        $this->assertSame(490, $resolver->resolve('FR', 300));
        $this->assertSame(790, $resolver->resolve('FR', 2000));
        $this->assertSame(1290, $resolver->resolve('FR', 40000));
    }

    // The ceiling is the heaviest parcel the tier covers, that weight included - a 1000 g tier posts a parcel of exactly 1000 g
    public function testTheCeilingOfATierIsIncluded(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['FR'], [[1000, 490], [5000, 790]])]);

        $this->assertSame(490, $resolver->resolve('FR', 1000));
        $this->assertSame(790, $resolver->resolve('FR', 1001));
    }

    // Written in whatever order an admin typed them, and read smallest first: the database hands them over as it likes, and the boundless tier is a null it sorts wherever it wants
    public function testTiersAreReadSmallestFirstWhateverOrderTheyWereWrittenIn(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['FR'], [[null, 1290], [5000, 790], [1000, 490]])]);

        $this->assertSame(490, $resolver->resolve('FR', 300));
    }

    public function testTheZoneNamingTheCountryWins(): void
    {
        $resolver = $this->createResolver([
            $this->zone('France', ['FR'], [[null, 490]]),
            $this->zone('Union européenne', ['BE', 'DE'], [[null, 990]]),
        ]);

        $this->assertSame(990, $resolver->resolve('BE', 300));
    }

    // The zone naming no country is the catch-all, where a country named nowhere else falls
    public function testACountryNamedNowhereFallsIntoTheCatchAll(): void
    {
        $resolver = $this->createResolver([
            $this->zone('France', ['FR'], [[null, 490]]),
            $this->zone('Reste du monde', [], [[null, 1990]]),
        ]);

        $this->assertSame(1990, $resolver->resolve('JP', 300));
        $this->assertSame(1990, $resolver->resolve(null, 300));
    }

    // Nothing written is nothing charged, which is what a shop that has posted no zone was already doing
    public function testAnEmptyGridChargesNothing(): void
    {
        $this->assertNull($this->createResolver([])->resolve('FR', 300));
    }

    public function testACountryNamedNowhereAndNoCatchAllChargesNothing(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['FR'], [[null, 490]])]);

        $this->assertNull($resolver->resolve('JP', 300));
    }

    // A shop that has not said what a 10 kg parcel costs has not said it costs the price of a 5 kg one
    public function testAParcelHeavierThanEveryTierChargesNothing(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['FR'], [[1000, 490], [5000, 790]])]);

        $this->assertNull($resolver->resolve('FR', 8000));
    }

    // The country is compared on the code and never on what was typed, the order carrying an ISO code either way
    public function testTheCountryIsMatchedWhateverCaseItComesIn(): void
    {
        $resolver = $this->createResolver([$this->zone('France', ['fr'], [[null, 490]])]);

        $this->assertSame(490, $resolver->resolve('FR', 300));
        $this->assertSame(490, $resolver->resolve(' fr ', 300));
    }

    // What a page states delivery starts at, the grid holding no single rate to name any more
    public function testTheCheapestTierOfTheWholeGridIsWhatAPageStates(): void
    {
        $resolver = $this->createResolver([
            $this->zone('France', ['FR'], [[1000, 490], [null, 1290]]),
            $this->zone('Monde', [], [[null, 1990]]),
        ]);

        $this->assertSame(490, $resolver->cheapest());
        $this->assertNull($this->createResolver([])->cheapest());
    }

    /**
     * @param list<ShippingZone> $zones
     */
    private function createResolver(array $zones): ShippingRateResolver
    {
        $repository = $this->createStub(ShippingZoneRepository::class);
        $repository->method('findActive')->willReturn($zones);

        return new ShippingRateResolver($repository);
    }

    /**
     * @param list<string>                     $countries
     * @param list<array{0: int|null, 1: int}> $tiers
     */
    private function zone(string $name, array $countries, array $tiers): ShippingZone
    {
        $zone = new ShippingZone()->setName($name)->setCountries($countries);

        foreach ($tiers as [$maxWeight, $price]) {
            $zone->addRate(new ShippingRate()->setMaxWeight($maxWeight)->setPrice($price));
        }

        return $zone;
    }
}
