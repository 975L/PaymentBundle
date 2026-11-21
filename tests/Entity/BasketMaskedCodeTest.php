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

// The code as the logs name it: a gift card's code is the money still on it
class BasketMaskedCodeTest extends TestCase
{
    // Its last four characters are enough to find the card in the back office, and nowhere near enough to spend it
    public function testALongCodeKeepsOnlyItsLastFourCharacters(): void
    {
        $this->assertSame('…DDDD', new Basket()->setDiscountCode('AAAABBBBCCCCDDDD')->getMaskedDiscountCode());
    }

    // Four characters out of a short code would be most of it
    public function testAShortCodeShowsNothingOfItself(): void
    {
        $this->assertSame('…', new Basket()->setDiscountCode('CODE1')->getMaskedDiscountCode());
        $this->assertSame('…', new Basket()->setDiscountCode('CODE-123')->getMaskedDiscountCode());
    }

    public function testABasketWithoutACodeHasNothingToMask(): void
    {
        $this->assertNull(new Basket()->getMaskedDiscountCode());
    }
}
