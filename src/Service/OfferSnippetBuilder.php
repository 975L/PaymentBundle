<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;

// Builds the schema.org nodes every Offer of the ecosystem shares - the shipping rate, the return policy and the seller's brand - out of this bundle's own settings, so a shop product and a gallery print publish the same delivery and return terms the checkout applies
class OfferSnippetBuilder
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ShippingRateResolverInterface $shippingRateResolver,
    ) {
    }

    // The OfferShippingDetails node of an article weighing $weight grams, priced on the grid to the one destination the shop names - a rate published without saying where it posts to is a rate for nowhere. A downloaded file costs nothing to deliver, which Google reads from a zero rate rather than from a missing node
    public function shippingDetails(?int $weight, bool $downloaded = false): array
    {
        $country = $this->country();
        if ('' === $country) {
            return [];
        }

        if ($downloaded) {
            return $this->shippingNode(0, $country, $this->deliveryTime([0, 0], [0, 0]));
        }

        // Nothing is claimed for an article nobody weighed: the grid answers per weight and per zone, and publishing one of its tiers as if it covered every parcel would be a guess
        if (null === $weight) {
            return [];
        }

        // A grid saying nothing about that parcel declares nothing, while a tier priced at zero is free shipping and said as such
        $shipping = $this->shippingRateResolver->resolve($country, $weight);
        if (null === $shipping || $shipping < 0) {
            return [];
        }

        // Both ranges or no delivery time at all: Google adds them up, and half of the sum is not one
        $handling = $this->dayRange('shop-handling-days');
        $transit = $this->dayRange('shop-transit-days');

        return $this->shippingNode($shipping, $country, null === $handling || null === $transit ? [] : $this->deliveryTime($handling, $transit));
    }

    // The return window the shop configured, for the country it sells to - no node at all when either is missing, an incomplete policy being what Google flags. An unfilled int setting reads as zero, so only a window above zero is published, while a downloaded file is not returnable whatever window the posted articles are granted
    public function returnPolicy(bool $downloaded = false): array
    {
        $country = $this->country();
        $days = max(0, (int) $this->configService->get('shop-return-days'));

        if ('' === $country || (!$downloaded && 0 === $days)) {
            return [];
        }

        $policy = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => $country,
            'returnPolicyCategory' => $downloaded ? 'https://schema.org/MerchantReturnNotPermitted' : 'https://schema.org/MerchantReturnFiniteReturnWindow',
        ];

        if (!$downloaded) {
            $policy['merchantReturnDays'] = $days;
        }

        // The terms are configured as a path on most sites, and a relative link is not one a search engine can follow
        $url = $this->absoluteUrl(trim((string) $this->configService->get('url-terms-of-sales')));
        if ('' !== $url) {
            $policy['merchantReturnLink'] = $url;
        }

        return $policy;
    }

    // The Brand node of $name, the shop's own name standing in when the article names none - what Google reads in place of a barcode on an item made in-house
    public function brand(?string $name = null): array
    {
        $name = trim((string) $name);
        if ('' === $name) {
            $name = trim((string) $this->configService->get('shop-name'));
        }

        return '' === $name ? [] : ['@type' => 'Brand', 'name' => $name];
    }

    // The one country the shop posts to, upper-cased as schema.org expects it
    private function country(): string
    {
        return strtoupper(trim((string) $this->configService->get('shop-shipping-country')));
    }

    // The OfferShippingDetails node for a rate in cents, the one country it posts to and, when known, how long the parcel takes
    private function shippingNode(int $rate, string $country, array $deliveryTime): array
    {
        $node = [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => number_format($rate / 100, 2, '.', ''),
                'currency' => strtoupper(trim((string) $this->configService->get('shop-currency'))),
            ],
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => $country,
            ],
        ];

        if ([] !== $deliveryTime) {
            $node['deliveryTime'] = $deliveryTime;
        }

        return $node;
    }

    // The business days a setting gives as a range an editor types ("1-3", or "2" for exactly two), null when it is empty, unreadable or written backwards
    /** @return array{int, int}|null */
    private function dayRange(string $setting): ?array
    {
        if (1 !== preg_match('/^\s*(\d{1,2})\s*(?:-\s*(\d{1,2}))?\s*$/', (string) $this->configService->get($setting), $days)) {
            return null;
        }

        $min = (int) $days[1];
        $max = isset($days[2]) ? (int) $days[2] : $min;

        return $max < $min ? null : [$min, $max];
    }

    // The ShippingDeliveryTime node: the days before the parcel leaves, then the days it travels
    /**
     * @param array{int, int} $handling
     * @param array{int, int} $transit
     */
    private function deliveryTime(array $handling, array $transit): array
    {
        $days = static fn (array $range): array => ['@type' => 'QuantitativeValue', 'minValue' => $range[0], 'maxValue' => $range[1], 'unitCode' => 'DAY'];

        return [
            '@type' => 'ShippingDeliveryTime',
            'handlingTime' => $days($handling),
            'transitTime' => $days($transit),
        ];
    }

    // A configured path turned into the address a search engine can follow, the host coming from "site-url" like everywhere else - an unconfigured host leaves nothing rather than a relative link
    private function absoluteUrl(string $url): string
    {
        if ('' === $url || 1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $siteUrl = rtrim(trim((string) $this->configService->get('site-url')), '/');

        return '' === $siteUrl ? '' : $siteUrl . '/' . ltrim($url, '/');
    }
}
