<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Twig\CatalogueExtension;
use PHPUnit\Framework\TestCase;

// Where the "continue shopping" button sends the customer, this bundle knowing of no shop of its own
class CatalogueExtensionTest extends TestCase
{
    public function testTheAddressComesFromWhicheverBundleSellsOutOfACatalogue(): void
    {
        $registry = $this->createStub(BasketItemProviderRegistry::class);
        $registry->method('getCatalogueUrl')->willReturn('/shop#products');

        $this->assertSame('/shop#products', new CatalogueExtension($registry)->url());
    }

    // Nothing installed has a listing to go back to: the button reads the null and is not drawn, rather than pointing at a route no site declares
    public function testASiteWithNoCatalogueIsSentNowhere(): void
    {
        $registry = $this->createStub(BasketItemProviderRegistry::class);
        $registry->method('getCatalogueUrl')->willReturn(null);

        $this->assertNull(new CatalogueExtension($registry)->url());
    }
}
