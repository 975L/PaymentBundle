<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Contract;

use c975L\PaymentBundle\Contract\BasketLine;
use c975L\PaymentBundle\Entity\Basket;
use PHPUnit\Framework\TestCase;

// What an order written years ago says when it is read today
class BasketLineTest extends TestCase
{
    // The shape is written down with the line, which is what a migration will read the day one is needed
    public function testALineCarriesTheShapeItWasWrittenIn(): void
    {
        $this->assertSame(BasketLine::VERSION, BasketLine::stamp(['total' => 1000])['v']);
    }

    // A key added since is filled with the very default the code used to read it with, so nothing an old order says changes
    public function testAKeyAddedSinceIsFilled(): void
    {
        $line = BasketLine::normalize(['item' => ['id' => 7, 'title' => 'Yesterday', 'price' => 1000], 'total' => 1000]);

        $this->assertSame(0.0, $line['item']['vat']);
        $this->assertSame(0, $line['totalVat']);
        $this->assertFalse($line['parent']['image']);
    }

    // Nothing already written is touched, a default being an answer only where the line holds none
    public function testWhatTheLineAlreadyHoldsIsLeftAlone(): void
    {
        $line = BasketLine::normalize(['item' => ['vat' => 20.0, 'media' => 'photo.webp'], 'totalVat' => 167, 'total' => 1000]);

        $this->assertSame(20.0, $line['item']['vat']);
        $this->assertSame('photo.webp', $line['item']['media']);
        $this->assertSame(167, $line['totalVat']);
    }

    // Item:Type tells a line that names a file apart from one that names none, and a default here would answer a question the provider never asked
    public function testTheKeysItemTypeReadsAreNotAnsweredFor(): void
    {
        $line = BasketLine::normalize(['item' => ['id' => 7], 'total' => 1000]);

        $this->assertArrayNotHasKey('file', $line['item']);
        $this->assertArrayNotHasKey('service', $line['item']);
        $this->assertArrayNotHasKey('requiresShipping', $line['item']);
    }

    // The one place an old order is caught up with: whatever reads the basket reads the current shape
    public function testTheBasketHandsOutNormalizedLines(): void
    {
        $basket = new Basket()->setItems(['product' => [7 => ['item' => ['id' => 7], 'total' => 1000]]]);

        $this->assertSame(0.0, $basket->getItems()['product'][7]['item']['vat']);
        $this->assertSame(0.0, $basket->toArray()['items']['product'][7]['item']['vat']);
    }
}
