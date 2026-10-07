<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\ShippingBasketItemProviderInterface;
use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Management\ShippingHealthCheckProvider;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Repository\ShippingZoneRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// An empty grid posts everything free on purpose, so what it does not say has to be said out loud rather than found on a month of orders
class ShippingHealthCheckProviderTest extends TestCase
{
    private const string SITE_ROOT = 'https://example.com/';
    private const string EDIT_URL = '/management/shipping-zone';

    public function testAnEmptyGridIsReportedRatherThanLeftSilent(): void
    {
        $results = $this->checks([]);

        $this->assertCount(1, $results);
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[0]['status']);
        $this->assertSame('label.health_check_shipping_grid_empty', $results[0]['summary']);
    }

    // An empty grid is a choice where nothing posted is on sale
    public function testAnEmptyGridIsCleanWhereNoParcelIsSold(): void
    {
        $results = $this->checks([], self::SITE_ROOT, false);

        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
        $this->assertSame('label.health_check_shipping_grid_unneeded', $results[0]['summary']);
    }

    public function testAGridWithADefaultZoneAndABoundlessTierIsClean(): void
    {
        $results = $this->checks([$this->zone('Monde', [], [[1000, 490], [null, 1990]])]);

        foreach ($results as $result) {
            $this->assertSame(HealthCheckResult::STATUS_OK, $result['status'], $result['summary']);
        }
    }

    // Site-wide rows are keyed on the site root, the dashboard rendering that key as the link it follows
    public function testTheRowsAreKeyedOnTheSiteRoot(): void
    {
        $results = $this->checks([$this->zone('Monde', [], [[null, 1990]])]);

        $this->assertSame(self::SITE_ROOT . '#shipping-catch-all', $results[0]['url']);
        $this->assertSame(self::SITE_ROOT . '#shipping-zones', $results[1]['url']);
        $this->assertSame(self::SITE_ROOT . '#shipping-zones-empty', $results[2]['url']);
    }

    // The keys lead nowhere, so every row points at the zone list instead - an empty grid included, it being where the first zone is written. Relative, the cron handing EasyAdmin no host of its own
    public function testEveryRowLinksToTheZoneList(): void
    {
        $rows = [...$this->checks([]), ...$this->checks([$this->zone('Monde', [], [[null, 1990]])])];

        foreach ($rows as $row) {
            $this->assertSame(self::EDIT_URL, $row['editUrl']);
        }
    }

    // A country named in no zone is posted free, which a shop selling abroad wants to know about
    public function testAGridWithNoDefaultZoneIsReported(): void
    {
        $row = $this->of($this->checks([$this->zone('France', ['FR'], [[null, 490]])]), ShippingHealthCheckProvider::ROW_CATCH_ALL);

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $row['status']);
        $this->assertSame('label.health_check_shipping_default_zone_none', $row['summary']);
    }

    // Two default zones is the resolver taking whichever the database hands over first, which nobody decided
    public function testTwoDefaultZonesAreReported(): void
    {
        $row = $this->of($this->checks([$this->zone('Monde', [], [[null, 1990]]), $this->zone('Ailleurs', [], [[null, 990]])]), ShippingHealthCheckProvider::ROW_CATCH_ALL);

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $row['status']);
        $this->assertSame('label.health_check_shipping_default_zone_several', $row['summary']);
    }

    // A zone whose tiers all stop short refuses a heavier parcel at checkout, a sale lost rather than a margin
    public function testAZoneWithoutABoundlessTierIsReported(): void
    {
        $row = $this->of($this->checks([$this->zone('Monde', [], [[1000, 490]])]), ShippingHealthCheckProvider::ROW_ZONES);

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $row['status']);
        $this->assertSame('label.health_check_shipping_zones_ko', $row['summary']);
        $this->assertFalse($row['details']['zones'][0]['boundless']);
    }

    // A zone with no tier at all posts every parcel free, said on a row of its own rather than mixed with the capped ones, which refuse
    public function testAZoneWithNoTariffAtAllIsReportedOnItsOwnRow(): void
    {
        $results = $this->checks([$this->zone('Monde', [], [])]);
        $row = $this->of($results, ShippingHealthCheckProvider::ROW_ZONES_EMPTY);

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $row['status']);
        $this->assertSame('label.health_check_shipping_zones_empty_ko', $row['summary']);
        $this->assertSame(['Monde'], $row['details']['zones']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->of($results, ShippingHealthCheckProvider::ROW_ZONES)['status']);
    }

    // One row for the whole grid rather than one per zone: a row keyed on a zone's id would outlive the zone it names, results being stored per url
    public function testEveryZoneIsReportedOnASingleRow(): void
    {
        $results = $this->checks([$this->zone('Monde', [], [[1000, 490]]), $this->zone('Europe', ['FR'], [])]);

        $this->assertCount(3, $results);
        $this->assertCount(2, $this->of($results, ShippingHealthCheckProvider::ROW_ZONES)['details']['zones']);
    }

    // Without a site url there is nothing to key a row on, and an empty run would have the runner delete the rows of the last one: thrown, the runner skips the provider and leaves them
    public function testNothingIsCheckedWithoutASiteUrl(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->checks([$this->zone('Monde', [], [[null, 1990]])], null);
    }

    /**
     * @param list<ShippingZone> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function checks(array $zones, ?string $siteRoot = self::SITE_ROOT, bool $shipsParcels = true): array
    {
        $repository = $this->createStub(ShippingZoneRepository::class);
        $repository->method('findActive')->willReturn($zones);

        $resolver = $this->createStub(SiteUrlResolver::class);
        $resolver->method('siteRoot')->willReturn($siteRoot);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('http://localhost' . self::EDIT_URL);

        $provider = $this->createStubForIntersectionOfInterfaces([BasketItemProviderInterface::class, ShippingBasketItemProviderInterface::class]);
        $provider->method('getKind')->willReturn('product');
        $provider->method('shipsParcels')->willReturn($shipsParcels);

        return new ShippingHealthCheckProvider(
            $repository,
            $resolver,
            $this->translator(),
            $adminUrlGenerator,
            new BasketItemProviderRegistry([$provider]),
        )->runChecks();
    }

    // The translation ids themselves, so the assertions read what the provider asked for rather than what a catalog answers
    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private function of(array $results, string $row): array
    {
        return array_values(array_filter($results, static fn (array $result): bool => $result['url'] === self::SITE_ROOT . $row))[0];
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
