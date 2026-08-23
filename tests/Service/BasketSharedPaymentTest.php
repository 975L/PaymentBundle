<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Entity\Basket;
use PHPUnit\Framework\TestCase;

// The two secrets of an order somebody else is asked to settle, and what each of them opens
class BasketSharedPaymentTest extends TestCase
{
    // The link handed to the payer is never the one that opens the delivery page: that one carries the name and the address the order is going to
    public function testTheShareTokenIsNotTheSecurityToken(): void
    {
        $basket = new Basket()
            ->setSecurityToken('aaaabbbbccccdddd')
            ->setShareToken('1111222233334444')
        ;

        $this->assertNotSame($basket->getSecurityToken(), $basket->getShareToken());
    }

    public function testAnOrdinaryOrderIsNotShared(): void
    {
        $this->assertFalse(new Basket()->isShared());
        $this->assertTrue(new Basket()->setShareToken('1111222233334444')->isShared());
    }

    // What the payer is asked for is what is left to pay, code deducted - the same figure the customer was shown
    public function testThePayerIsAskedForWhatIsLeftToPay(): void
    {
        $basket = new Basket()
            ->setTotal(4000)
            ->setShipping(500)
            ->setDiscountAmount(1000)
            ->setShareToken('1111222233334444')
        ;

        $this->assertSame(3500, $basket->getPayable());
    }

    // A card covering the whole order leaves nothing for anyone to settle, and the link would open a payment page for zero
    public function testAnOrderCoveredInFullHasNothingLeftToAskFor(): void
    {
        $basket = new Basket()
            ->setTotal(2000)
            ->setShipping(500)
            ->setDiscountAmount(9000)
            ->setShareToken('1111222233334444')
        ;

        $this->assertSame(0, $basket->getPayable());
    }
}
