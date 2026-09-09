<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// What holds the shipped markup to the controller BasketBehaviourTest runs: that test mounts a page of its own, so it says nothing about the templates. The bar showed its count and its total in it and nowhere else, its fixture carrying a controller the template did not
class BasketMarkupTest extends TestCase
{
    private const string NAVBAR = 'templates/components/Basket/Navbar.html.twig';
    private const string BUTTON = 'templates/components/Basket/ViewButton.html.twig';

    // The bar is placed once in the site layout, outside every element a shop, a campaign or a print offer carries: without a controller of its own, its two targets belong to no instance and stay empty under a bar that shows
    public function testTheBarMountsTheControllerItsCountersAreTargetsOf(): void
    {
        $this->assertStringContainsString('data-controller="basket"', $this->read(self::NAVBAR), 'The bar waits for a controller around it, which the layout it is placed in never gives it.');
    }

    // The two the controller writes into, named as it names them (see updateBasketCounters in assets/js/basket.js)
    public function testTheButtonCarriesTheCountAndTheTotalTheControllerWrites(): void
    {
        $button = $this->read(self::BUTTON);

        $this->assertStringContainsString('data-basket-target="quantity"', $button);
        $this->assertStringContainsString('data-basket-target="total"', $button);
    }

    private function read(string $path): string
    {
        $file = \dirname(__DIR__, 2) . '/' . $path;

        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }
}
