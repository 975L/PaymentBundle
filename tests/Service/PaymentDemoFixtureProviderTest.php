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
use c975L\PaymentBundle\Service\PaymentDemoFixtureProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// What a demo shop charges to post a parcel: settings, not history
class PaymentDemoFixtureProviderTest extends TestCase
{
    /** @return list<ShippingZone> */
    private function fixtures(): array
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => 'translated:' . $id);

        return iterator_to_array(new PaymentDemoFixtureProvider($translator)->getDemoFixtures(), false);
    }

    // The fewest that shows what a zone is for: the country the shop is in, everything around it at another price, and the catch-all taking the rest
    public function testItSeedsThreeActiveZonesThatShareNoCountry(): void
    {
        [$home, $around, $elsewhere] = $this->fixtures();

        $this->assertCount(3, $this->fixtures());
        $this->assertSame([], array_intersect($home->getCountries(), $around->getCountries()));
        $this->assertNotSame([], $home->getCountries());
        $this->assertNotSame([], $around->getCountries());

        foreach ($this->fixtures() as $zone) {
            $this->assertTrue($zone->isActive());
        }

        // No countries is what the shipping health check counts, and a grid without it ships free to every country the first two miss
        $this->assertSame([], $elsewhere->getCountries());
    }

    // A bracket is read cheap-to-heavy, and the last one carries no ceiling - which is what makes it the last
    public function testEachGridClimbsAndEndsOpenEnded(): void
    {
        foreach ($this->fixtures() as $zone) {
            $rates = $zone->getRates()->toArray();
            $this->assertGreaterThan(1, \count($rates));

            $previousWeight = 0;
            $previousPrice = 0;
            foreach ($rates as $index => $rate) {
                $this->assertInstanceOf(ShippingRate::class, $rate);
                $this->assertGreaterThan($previousPrice, $rate->getPrice());
                $previousPrice = $rate->getPrice();

                if ($index === \count($rates) - 1) {
                    $this->assertNull($rate->getMaxWeight());

                    continue;
                }

                $this->assertGreaterThan($previousWeight, $rate->getMaxWeight());
                $previousWeight = $rate->getMaxWeight();
            }
        }
    }

    // Posting further costs more, whatever the bracket - a grid where two zones cross teaches the wrong thing
    public function testPostingFurtherCostsMoreBracketForBracket(): void
    {
        [$home, $around, $elsewhere] = $this->fixtures();

        foreach ($home->getRates() as $index => $rate) {
            $this->assertGreaterThan($rate->getPrice(), $around->getRates()[$index]->getPrice());
            $this->assertGreaterThan($around->getRates()[$index]->getPrice(), $elsewhere->getRates()[$index]->getPrice());
        }
    }

    // A demo is reloaded often, and a date taken from the clock would say something else in every take
    public function testTheCreationDateIsFixedRatherThanTakenFromTheClock(): void
    {
        foreach ($this->fixtures() as $zone) {
            $this->assertSame('2026-01-05', $zone->getCreation()->format('Y-m-d'));
        }
    }
}
