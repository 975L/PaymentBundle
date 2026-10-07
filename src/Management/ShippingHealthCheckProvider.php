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
use c975L\ConfigBundle\Management\HealthCheckExhaustiveInterface;
use c975L\ConfigBundle\Management\HealthCheckSiteWideInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Controller\Management\ShippingZoneCrudController;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Repository\ShippingZoneRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the delivery grid does not say and would cost the shop in silence, an empty grid posting every parcel free. Site-wide, the grid being written once for the whole shop. Exhaustive, an empty grid and a filled one keying their rows differently
class ShippingHealthCheckProvider implements HealthCheckSiteWideInterface, HealthCheckExhaustiveInterface
{
    public const string KIND = 'payment-shipping';

    // Suffixes the rows are keyed by, appended to the site root so each check keeps a history of its own (results are stored per url and kind)
    public const string ROW_GRID = '#shipping-grid';
    public const string ROW_CATCH_ALL = '#shipping-catch-all';
    public const string ROW_ZONES = '#shipping-zones';
    public const string ROW_ZONES_EMPTY = '#shipping-zones-empty';

    public function __construct(
        private readonly ShippingZoneRepository $shippingZoneRepository,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    public function runChecks(): array
    {
        // Thrown rather than an empty run: the provider being exhaustive, an empty run would have the runner delete every row it wrote before, where a provider that throws is skipped and its rows left as they were (see HealthCheckRunner::runProvider())
        $siteRoot = $this->siteUrlResolver->siteRoot();
        if (null === $siteRoot) {
            throw new \RuntimeException('No site url to key the shipping health check rows on.');
        }

        $zones = $this->shippingZoneRepository->findActive();

        if ([] === $zones) {
            return [[
                'url' => $siteRoot . self::ROW_GRID,
                'label' => $this->trans('label.health_check_shipping_grid'),
                'status' => HealthCheckResult::STATUS_WARNING,
                'summary' => $this->trans('label.health_check_shipping_grid_empty'),
                'details' => ['zones' => 0],
                'editUrl' => $this->editUrl(),
            ]];
        }

        return [
            $this->checkCatchAll($siteRoot, $zones),
            $this->checkZones($siteRoot, $zones),
            $this->checkEmptyZones($siteRoot, $zones),
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
            'editUrl' => $this->editUrl(),
        ];
    }

    // The zones whose tiers all stop short, a heavier parcel being refused at checkout (see BasketService::validate()), named on one row: a row keyed on a zone's id would outlive the zone, results being stored per url
    /**
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
            $boundless = $rates->exists(static fn (int $key, $rate): bool => null === $rate->getMaxWeight());
            $name = (string) $zone->getName();

            // A zone with no tier at all is the other row's, posting free rather than refusing
            if (!$rates->isEmpty() && !$boundless) {
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
            'editUrl' => $this->editUrl(),
        ];
    }

    // The zones with no tier at all, which post every parcel free - a risk on the margin, where the capped ones are a risk on the sale
    /**
     * @param list<ShippingZone> $zones
     *
     * @return array<string, mixed>
     */
    private function checkEmptyZones(string $siteRoot, array $zones): array
    {
        $offenders = array_values(array_map(
            static fn (ShippingZone $zone): string => (string) $zone->getName(),
            array_filter($zones, static fn (ShippingZone $zone): bool => $zone->getRates()->isEmpty()),
        ));

        return [
            'url' => $siteRoot . self::ROW_ZONES_EMPTY,
            'label' => $this->trans('label.health_check_shipping_zones_empty'),
            'status' => [] === $offenders ? HealthCheckResult::STATUS_OK : HealthCheckResult::STATUS_WARNING,
            'summary' => [] === $offenders
                ? $this->trans('label.health_check_shipping_zones_empty_ok', ['%count%' => \count($zones)])
                : $this->trans('label.health_check_shipping_zones_empty_ko', ['%count%' => \count($offenders), '%names%' => implode(', ', $offenders)]),
            'details' => ['zones' => $offenders],
            'editUrl' => $this->editUrl(),
        ];
    }

    // The zone list in the back office, the rows' own urls being keys rather than pages. Kept relative: from the console, with no admin context, EasyAdmin hands an absolute url on whatever host the request context holds
    private function editUrl(): string
    {
        $url = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(ShippingZoneCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl()
        ;

        return (string) preg_replace('#^https?://[^/]+#', '', $url);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'payment');
    }
}
