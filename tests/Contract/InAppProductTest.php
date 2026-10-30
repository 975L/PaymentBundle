<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Contract;

use c975L\PaymentBundle\Contract\InAppProduct;
use PHPUnit\Framework\TestCase;

// The product ids both stores accept, derived from a slug and never typed
class InAppProductTest extends TestCase
{
    public function testASlugBecomesAnIdBothStoresAccept(): void
    {
        $this->assertSame('back.chateau_hurlton_orage', InAppProduct::idFor('back', 'chateau-hurlton-orage'));
        $this->assertSame('back.chateau_hurlton_orage', (new InAppProduct(InAppProduct::idFor('back', 'chateau-hurlton-orage'), 100, ['fr' => 'Orage']))->productId);
    }

    // Google refuses a hyphen, and Apple never gives an id back once used: a wrong one must fail here, not at the store
    public function testAnIdWithAHyphenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InAppProduct('back.chateau-hurlton', 100, ['fr' => 'Orage']);
    }
}
