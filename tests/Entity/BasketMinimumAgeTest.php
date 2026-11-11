<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use c975L\PaymentBundle\Entity\Basket;
use PHPUnit\Framework\TestCase;

// The age the checkout waits behind a verification for: the highest any line declares, whatever bundle sold it, and none for an order written before lines carried one
class BasketMinimumAgeTest extends TestCase
{
    // A ranged age read by its leading number, the highest across kinds winning
    public function testTheHighestAgeAnyLineDeclaresIsTheBaskets(): void
    {
        $basket = new Basket()->setItems([
            'product' => [1 => ['age' => '3-8'], 2 => ['age' => '18-']],
            'other' => [7 => ['age' => '16']],
        ]);

        $this->assertSame(18, $basket->getMinimumAge());
    }

    // A null age, a line written before lines carried one, an empty basket: no age at all
    public function testABasketWhoseLinesDeclareNoAgeHasNone(): void
    {
        $this->assertNull(new Basket()->setItems(['product' => [1 => ['age' => null], 2 => []]])->getMinimumAge());
        $this->assertNull(new Basket()->getMinimumAge());
    }
}
