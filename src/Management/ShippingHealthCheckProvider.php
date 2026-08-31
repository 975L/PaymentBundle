<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckSiteWideInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Repository\ShippingZoneRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the delivery grid does not say, and would cost the shop in silence. An empty grid posts every parcel free - which is deliberate, nothing written being nothing charged - so it has to be said out loud somewhere rather than discovered on a month of orders
//
// Site-wide, the grid being written once for the whole shop: its rows belong in the "Site" section rather than under "Pages", where their keys would be rendered as page urls
class ShippingHealthCheckProvider implements HealthCheckSiteWideInterface
{
    public const string KIND = 'payment-shipping';

    // Suffixes the rows are keyed by, appended to the site root so each check keeps a history of its own (results are stored per url and kind)
    public const string ROW_GRID = '#shipping-grid';
    public const string ROW_CATCH_ALL = '#shipping-catch-all';
    public const string ROW_ZONES = '#shipping-zones';

    public function __construct(
        private readonly ShippingZoneRepository $shippingZoneRepository,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    public function runChecks(): array
    {
        // Same guard as every site-wide check: without a site url there is nothing to key a row on
        $siteRoot = $this->siteUrlResolver->siteRoot();
        if (null === $siteRoot) {
            return [];
        }

        $zones = $this->shippingZoneRepository->findActive();

        if ([] === $zones) {
            return [[
                'url' => $siteRoot . self::ROW_GRID,
                'label' => $this->trans('label.health_check_shipping_grid'),
                'status' => HealthCheckResult::STATUS_WARNING,
                'summary' => $this->trans('label.health_check_shipping_grid_empty'),
                'details' => ['zones' => 0],
            ]];
        }

        return [
            $this->checkCatchAll($siteRoot, $zones),
            $this->checkZones($siteRoot, $zones),
        ];
    }

    /**
     * A country named in no zone falls into the catch-all, and without one it is posted free.
     *
     * Two catch-alls is the other half of the same question: the resolver takes the first it is handed, which is
     * the database's own order and not a decision anybody made.
     *
     * @param list<ShippingZone> $zones
     *
     * @return array<string, mixed>
     */
    private function checkCatchAll(string $siteRoot, array $zones): array
    {
        $catchAll = array_values(array_filter($zones, static fn (ShippingZone $zone): bool => $zone->isCatchAll()));
        $names = array_map(static fn (ShippingZone $zone): string => (string) $zone->getName(), $catchAll);

        return [
            'url' => $siteRoot . self::ROW_CATCH_ALL,
            'label' => $this->trans('label.health_check_shipping_default_zone'),
            'status' => match (\count($catchAll)) {
                1 => HealthCheckResult::STATUS_OK,
                default => HealthCheckResult::STATUS_WARNING,
            },
            'summary' => match (\count($catchAll)) {
                0 => $this->trans('label.health_check_shipping_default_zone_none'),
                1 => $this->trans('label.health_check_shipping_default_zone_ok', ['%zone%' => $names[0]]),
                default => $this->trans('label.health_check_shipping_default_zone_several', ['%zones%' => implode(', ', $names)]),
            },
            'details' => ['zones' => $names],
        ];
    }

    /**
     * The zones a parcel can leave free of charge, named on one row rather than one row per zone.
     *
     * One row per zone would key itself on the zone's id, and a zone deleted afterwards would leave its row behind
     * for good - results being stored per url, nothing ever comes back to say the zone is gone.
     *
     * @param list<ShippingZone> $zones
     *
     * @return array<string, mixed>
     */
    private function checkZones(string $siteRoot, array $zones): array
    {
        $offenders = [];
        $details = [];

        foreach ($zones as $zone) {
            $rates = $zone->getRates();
            // A zone whose tiers all stop short posts a heavier parcel free, which is exactly what a carrier does not do
            $boundless = $rates->exists(static fn (int $key, $rate): bool => null === $rate->getMaxWeight());
            $name = (string) $zone->getName();

            if ($rates->isEmpty() || !$boundless) {
                $offenders[] = $name;
            }

            $details[] = ['zone' => $name, 'rates' => $rates->count(), 'boundless' => $boundless, 'countries' => $zone->getCountries()];
        }

        return [
            'url' => $siteRoot . self::ROW_ZONES,
            'label' => $this->trans('label.health_check_shipping_zones'),
            'status' => [] === $offenders ? HealthCheckResult::STATUS_OK : HealthCheckResult::STATUS_WARNING,
            'summary' => [] === $offenders
                ? $this->trans('label.health_check_shipping_zones_ok', ['%count%' => \count($zones)])
                : $this->trans('label.health_check_shipping_zones_ko', ['%count%' => \count($offenders), '%names%' => implode(', ', $offenders)]),
            'details' => ['zones' => $details],
        ];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'payment');
    }
}
