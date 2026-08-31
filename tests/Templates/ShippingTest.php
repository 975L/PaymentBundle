<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;

// The component saying what delivery costs. Its three props are what lets the block showcase render it on a site with no shop configuration, and the way they fall back is what keeps a real page reading that configuration exactly as before
class ShippingTest extends TestCase
{
    private function component(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/components/Basket/Shipping.html.twig');
    }

    // A page placing the block hands over nothing, so each prop falls back on its own - the amount on the grid, the two others on the configuration they have always read
    public function testEachPropFallsBackToItsOwnConfiguration(): void
    {
        $component = $this->component();

        $this->assertStringContainsString('{% set shipping = shipping is defined ? shipping : payment_shipping_from() %}', $component);
        $this->assertStringContainsString("{% set free = free is defined ? free : config('shop-shipping-free') %}", $component);
        $this->assertStringContainsString("{% set currency = currency is defined ? currency : config('shop-currency') %}", $component);
    }
}
