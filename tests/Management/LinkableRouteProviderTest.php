<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Service\SiteLocales;
use c975L\PaymentBundle\Management\LinkableRouteProvider;
use PHPUnit\Framework\TestCase;

// The basket page offered to SiteBundle menus - its route name is checked against the controllers by ManagementTargetsTest, this one guards what the entry says
class LinkableRouteProviderTest extends TestCase
{
    public function testItOffersTheBasketPageAndTheOrderHistory(): void
    {
        $routes = new LinkableRouteProvider(new SiteLocales(['fr', 'en'], 'fr'))->getLinkableRoutes();

        $this->assertSame('label.basket', $routes['basket_display']['label']);
        $this->assertSame('label.my_orders', $routes['customer_orders']['label']);
    }

    // Both answer in every language the site declares, a basket and an order history holding the visitor's own data rather than content written in one language
    public function testBothAnswerInEveryLanguageTheSiteDeclares(): void
    {
        foreach (new LinkableRouteProvider(new SiteLocales(['fr', 'en'], 'fr'))->getLinkableRoutes() as $entry) {
            $this->assertSame(['fr', 'en'], $entry['locales']);
        }
    }

    // The label is a key of this bundle's own catalog, not of ShopBundle's - a site running Payment without Shop would otherwise show a raw key in its navbar
    public function testEveryLabelIsTranslatedInThisBundleInEveryLocale(): void
    {
        foreach (new LinkableRouteProvider(new SiteLocales(['fr', 'en'], 'fr'))->getLinkableRoutes() as $entry) {
            $this->assertSame('payment', $entry['translation_domain']);
        }

        foreach (['en', 'fr', 'es'] as $locale) {
            $xliff = simplexml_load_file(__DIR__ . '/../../translations/payment.' . $locale . '.xlf');
            $sources = [];
            foreach ($xliff->file->body->{'trans-unit'} as $unit) {
                $sources[(string) $unit->source] = (string) $unit->target;
            }

            foreach (new LinkableRouteProvider(new SiteLocales(['fr', 'en'], 'fr'))->getLinkableRoutes() as $entry) {
                $this->assertArrayHasKey($entry['label'], $sources, sprintf('"%s" has no %s translation', $entry['label'], $locale));
                $this->assertNotSame('', $sources[$entry['label']]);
            }
        }
    }

    // Only the basket page: the other routes of this bundle are checkout steps, reached from the basket and meaningless in a menu
    public function testTheCheckoutStepsAreNotOffered(): void
    {
        $routes = new LinkableRouteProvider(new SiteLocales(['fr', 'en'], 'fr'))->getLinkableRoutes();

        $this->assertSame(['basket_display', 'customer_orders'], array_keys($routes));
    }
}
