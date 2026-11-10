<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Service\OfferSnippetBuilder;
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use PHPUnit\Framework\TestCase;

// The shipping, return and brand nodes every Offer of the ecosystem shares, built from this bundle's settings
class OfferSnippetBuilderTest extends TestCase
{
    private const array CONFIG = [
        'shop-shipping-country' => 'fr',
        'shop-currency' => 'eur',
        'shop-return-days' => 14,
        'shop-handling-days' => '1-2',
        'shop-transit-days' => '2-4',
        'shop-name' => 'Catalog',
        'url-terms-of-sales' => '/terms',
        'site-url' => 'https://example.org/',
    ];

    // The builder against the given settings and a grid answering $rate for any parcel
    private function builder(array $config = self::CONFIG, ?int $rate = 450): OfferSnippetBuilder
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): mixed => $config[$key] ?? null);

        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('resolve')->willReturn($rate);

        return new OfferSnippetBuilder($configService, $resolver);
    }

    public function testAWeighedArticleIsPricedOnTheGridToTheShopsCountry(): void
    {
        $node = $this->builder()->shippingDetails(500);

        $this->assertSame('OfferShippingDetails', $node['@type']);
        $this->assertSame(['@type' => 'MonetaryAmount', 'value' => '4.50', 'currency' => 'EUR'], $node['shippingRate']);
        $this->assertSame(['@type' => 'DefinedRegion', 'addressCountry' => 'FR'], $node['shippingDestination']);
        $this->assertSame(['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 2, 'unitCode' => 'DAY'], $node['deliveryTime']['handlingTime']);
        $this->assertSame(['@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 4, 'unitCode' => 'DAY'], $node['deliveryTime']['transitTime']);
    }

    public function testNothingIsClaimedWithoutCountryWeightOrRate(): void
    {
        $this->assertSame([], $this->builder(['shop-shipping-country' => ''] + self::CONFIG)->shippingDetails(500));
        $this->assertSame([], $this->builder()->shippingDetails(null));
        $this->assertSame([], $this->builder(self::CONFIG, null)->shippingDetails(500));
    }

    // Half of the delivery time is not one, Google adding both ranges up
    public function testAnUnreadableDayRangeDropsTheDeliveryTime(): void
    {
        $node = $this->builder(['shop-transit-days' => '4-2'] + self::CONFIG)->shippingDetails(500);

        $this->assertArrayNotHasKey('deliveryTime', $node);
    }

    public function testADownloadedFileShipsForFreeAndCannotBeReturned(): void
    {
        $builder = $this->builder(['shop-return-days' => 0] + self::CONFIG);

        $this->assertSame('0.00', $builder->shippingDetails(null, true)['shippingRate']['value']);
        $this->assertSame('https://schema.org/MerchantReturnNotPermitted', $builder->returnPolicy(true)['returnPolicyCategory']);
        $this->assertArrayNotHasKey('merchantReturnDays', $builder->returnPolicy(true));
    }

    // A download leaves at once and travels no day, whatever the posted parcels take
    public function testADownloadedFileIsDeliveredInNoTime(): void
    {
        $deliveryTime = $this->builder()->shippingDetails(null, true)['deliveryTime'];

        $this->assertSame(0, $deliveryTime['handlingTime']['maxValue']);
        $this->assertSame(0, $deliveryTime['transitTime']['maxValue']);
    }

    // "2" reads as exactly two days
    public function testASingleDayIsBothEndsOfTheRange(): void
    {
        $node = $this->builder(['shop-handling-days' => ' 2 '] + self::CONFIG)->shippingDetails(500);

        $this->assertSame(2, $node['deliveryTime']['handlingTime']['minValue']);
        $this->assertSame(2, $node['deliveryTime']['handlingTime']['maxValue']);
    }

    // An absolute link is kept as typed, a path without a known host is dropped rather than published relative
    public function testTheTermsLinkIsNeverRelative(): void
    {
        $this->assertSame('https://other.org/terms', $this->builder(['url-terms-of-sales' => 'https://other.org/terms'] + self::CONFIG)->returnPolicy()['merchantReturnLink']);
        $this->assertArrayNotHasKey('merchantReturnLink', $this->builder(['site-url' => ''] + self::CONFIG)->returnPolicy());
    }

    public function testTheReturnWindowLinksTheTermsAsAnAbsoluteUrl(): void
    {
        $this->assertSame([
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'FR',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 14,
            'merchantReturnLink' => 'https://example.org/terms',
        ], $this->builder()->returnPolicy());
    }

    public function testAnUnansweredReturnWindowPublishesNoPolicy(): void
    {
        $this->assertSame([], $this->builder(['shop-return-days' => null] + self::CONFIG)->returnPolicy());
    }

    public function testTheBrandFallsBackOnTheShopsName(): void
    {
        $this->assertSame(['@type' => 'Brand', 'name' => 'Author'], $this->builder()->brand(' Author '));
        $this->assertSame(['@type' => 'Brand', 'name' => 'Catalog'], $this->builder()->brand(''));
        $this->assertSame([], $this->builder(['shop-name' => null] + self::CONFIG)->brand(null));
    }
}
