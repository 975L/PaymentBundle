<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\LocalizedUrlGenerator;
use c975L\PaymentBundle\Service\PaymentLinkLocalizer;
use PHPUnit\Framework\TestCase;

// A stored link to the basket or to the orders, followed into the language the page around it is read in (see PaymentLinkLocalizer)
class PaymentLinkLocalizerTest extends TestCase
{
    // The three screens a visitor navigates to on purpose, each read in English
    public function testEachOfTheThreeScreensIsReadInTheLanguageBeingRead(): void
    {
        $localizer = $this->localizer();

        $this->assertSame('/en/shop/basket/display', $localizer->localize('/shop/basket/display'));
        $this->assertSame('/en/shop/basket/validate', $localizer->localize('/shop/basket/validate'));
        $this->assertSame('/en/account/orders', $localizer->localize('/account/orders'));
    }

    // An anchor and a query string travel with the link: "#coordinates" is the very form ValidateButton links with
    public function testWhatFollowsThePathTravelsWithTheLink(): void
    {
        $localizer = $this->localizer();

        $this->assertSame('/en/shop/basket/validate#coordinates', $localizer->localize('/shop/basket/validate#coordinates'));
        $this->assertSame('/en/account/orders?page=2', $localizer->localize('/account/orders?page=2'));
    }

    // A link inside a rich text is a link like any other; an external url and the same words in the prose are not
    public function testTheLinksOfARichTextAreRewrittenAndNothingElse(): void
    {
        $this->assertSame(
            'Voir <a href="/en/shop/basket/display">le panier</a> ou <a href="https://example.com/shop/basket/display">ailleurs</a>, pas /shop/basket/display en toutes lettres',
            $this->localizer()->localize('Voir <a href="/shop/basket/display">le panier</a> ou <a href="https://example.com/shop/basket/display">ailleurs</a>, pas /shop/basket/display en toutes lettres'),
        );
    }

    // A step of the checkout, an endpoint, an order of its own: none of them is one of the three screens
    public function testAnythingElseIsGivenBackUntouched(): void
    {
        $localizer = $this->localizer();

        foreach (['/account/orders/ABC123', '/shop/basket/json', '/shop/basket/displayed', 'https://example.com/account/orders', '#coordinates', ''] as $value) {
            $this->assertSame($value, $localizer->localize($value));
        }
    }

    // The generator's own rule reduced to what matters here: every route read under "/en"
    private function localizer(): PaymentLinkLocalizer
    {
        $paths = [
            'basket_display' => '/shop/basket/display',
            'basket_validate' => '/shop/basket/validate',
            'customer_orders' => '/account/orders',
        ];

        $generator = $this->createStub(LocalizedUrlGenerator::class);
        $generator->method('path')->willReturnCallback(static fn (string $route): string => '/en' . $paths[$route]);

        return new PaymentLinkLocalizer($generator);
    }
}
