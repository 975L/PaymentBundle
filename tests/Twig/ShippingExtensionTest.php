<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Twig\ShippingExtension;
use PHPUnit\Framework\TestCase;

// What a page may state delivery starts at, the grid holding one price per zone and per weight rather than a single rate
class ShippingExtensionTest extends TestCase
{
    public function testTheLowestPriceOfTheWholeGridIsWhatAPageStates(): void
    {
        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('cheapest')->willReturn(490);

        $this->assertSame(490, new ShippingExtension($resolver)->from());
    }

    // A shop that has written no tier says nothing rather than promising free delivery: the block reads the null and stays silent
    public function testAShopWithNoTierStatesNothing(): void
    {
        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('cheapest')->willReturn(null);

        $this->assertNull(new ShippingExtension($resolver)->from());
    }

    // Zero is a tier of its own - a shop posting light parcels free - and it is not the same answer as a grid holding nothing
    public function testATierPricedAtZeroIsStatedAsZeroAndNotAsNothing(): void
    {
        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('cheapest')->willReturn(0);

        $this->assertSame(0, new ShippingExtension($resolver)->from());
    }
}
