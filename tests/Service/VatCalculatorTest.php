<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Service\VatCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The tax a basket carries, prices being held VAT included.
 *
 * Nothing is stored: the rate is the one frozen on the line when the item was added, and the shipping and the
 * discount are the basket's own columns, so the same basket answers the same amounts however long after the sale.
 */
class VatCalculatorTest extends TestCase
{
    // 20 % of a hundred euros sold VAT included is 16,67 € - taken out of the price, not added to it
    public function testTheTaxIsTakenOutOfWhatTheCustomerPays(): void
    {
        $breakdown = $this->calculator()->breakdown($this->basket([['total' => 10000, 'vat' => 20.0]]));

        $this->assertSame(1667, $breakdown['amount']);
        $this->assertSame(8333, $breakdown['rates'][0]['base']);
        $this->assertSame(20.0, $breakdown['rates'][0]['rate']);
    }

    // Two rates in one basket are two lines of the recap, sorted by rate, never merged into an average nobody can justify
    public function testEachRateAnswersForItself(): void
    {
        $breakdown = $this->calculator()->breakdown($this->basket([
            ['total' => 10000, 'vat' => 20.0],
            ['total' => 5000, 'vat' => 5.5],
        ]));

        $this->assertCount(2, $breakdown['rates']);
        $this->assertSame(5.5, $breakdown['rates'][0]['rate']);
        $this->assertSame(261, $breakdown['rates'][0]['amount']);
        $this->assertSame(20.0, $breakdown['rates'][1]['rate']);
        $this->assertSame(1667, $breakdown['rates'][1]['amount']);
        $this->assertSame(1928, $breakdown['amount']);
    }

    // The shipping is taxed at the rate of the goods it carries: with two rates it is shared between them, in proportion of what each one weighs
    public function testTheShippingIsSharedBetweenTheRatesItCarries(): void
    {
        $basket = $this->basket([
            ['total' => 10000, 'vat' => 20.0],
            ['total' => 10000, 'vat' => 5.5],
        ]);
        $basket->setShipping(1000);

        $breakdown = $this->calculator()->breakdown($basket);

        $this->assertSame(10500, $breakdown['rates'][0]['total']);
        $this->assertSame(10500, $breakdown['rates'][1]['total']);
        $this->assertSame(547 + 1750, $breakdown['amount']);
    }

    // A promotional code lowers what the goods are sold for, so it lowers the tax with them
    public function testAPromotionalCodeLowersTheBase(): void
    {
        $basket = $this->basket([['total' => 12000, 'vat' => 20.0]]);
        $basket->setDiscountKind('discount');
        $basket->setDiscountAmount(2000);

        $this->assertSame(1667, $this->calculator()->breakdown($basket)['amount']);
    }

    // A gift card pays the order, it does not discount it: the goods are still sold for what they are worth, and the state is owed the same tax
    public function testAGiftCardPaysTheOrderWithoutLoweringTheTax(): void
    {
        $basket = $this->basket([['total' => 12000, 'vat' => 20.0]]);
        $basket->setDiscountKind('gift_card');
        $basket->setDiscountAmount(2000);

        $this->assertSame(2000, $this->calculator()->breakdown($basket)['amount']);
    }

    // A card sold is money bought in advance, taxed the day it is spent: it weighs nothing in the base of the order that sold it
    public function testACardSoldIsLeftOutOfTheBase(): void
    {
        $basket = $this->basket([
            ['total' => 10000, 'vat' => 20.0],
            ['total' => 5000, 'vat' => 20.0, 'giftCard' => true],
        ]);

        $breakdown = $this->calculator()->breakdown($basket);

        $this->assertCount(1, $breakdown['rates']);
        $this->assertSame(10000, $breakdown['rates'][0]['total']);
    }

    // A basket holding nothing taxable answers zero rather than a rate at 0, which a recap would print for nothing
    public function testNothingTaxableAnswersNoRateAtAll(): void
    {
        $basket = $this->basket([['total' => 5000, 'vat' => 0.0]]);
        $basket->setShipping(1000);

        $this->assertSame(['rates' => [], 'amount' => 0], $this->calculator()->breakdown($basket));
    }

    // The shares of the shipping are rounded one by one: the last rate takes what the roundings left, so they add up to the shipping and not to a cent more
    public function testTheRoundingsNeverInventACent(): void
    {
        $basket = $this->basket([
            ['total' => 3333, 'vat' => 20.0],
            ['total' => 3333, 'vat' => 10.0],
            ['total' => 3334, 'vat' => 5.5],
        ]);
        $basket->setShipping(1001);

        $breakdown = $this->calculator()->breakdown($basket);

        $this->assertSame(3333 + 3333 + 3334 + 1001, array_sum(array_column($breakdown['rates'], 'total')));
    }

    // The one place a line's own rate is read, so the page, the email and the invoice state the same rate as the breakdown they sit beside
    public function testALineAnswersTheRateItWasAddedWith(): void
    {
        $basket = $this->basket([['total' => 5000, 'vat' => 5.5]]);

        $this->assertSame(5.5, $this->calculator()->lineRate('product', $basket->getItems()['product'][0]));
    }

    // Money bought in advance is taxed the day it is spent: a card states no rate at all, and not the one the catalogue sells it under
    public function testACardSoldStatesNoRate(): void
    {
        $basket = $this->basket([['total' => 5000, 'vat' => 20.0, 'giftCard' => true]]);

        $this->assertNull($this->calculator()->lineRate('product', $basket->getItems()['product'][0]));
    }

    // Null and not zero: an order placed before the rate was kept carries a zero it was never charged at, and "0 %" printed on a line is a statement only the shop's own mentions can make
    public function testALineTaxedAtNoRateAnswersNullRatherThanZero(): void
    {
        $basket = $this->basket([['total' => 5000, 'vat' => 0.0]]);

        $this->assertNull($this->calculator()->lineRate('product', $basket->getItems()['product'][0]));
    }

    // Two lines taxed at a rate and sold for nothing - samples, counterparts given away - weigh nothing to share the shipping over. Answered rather than divided by, which used to be a 500 on the basket page and on every email that prints a breakdown
    public function testShippingOnABasketWorthNothingIsLeftUntaxedRatherThanCrashing(): void
    {
        $basket = $this->basket([
            ['total' => 0, 'vat' => 20.0],
            ['total' => 0, 'vat' => 5.5],
        ]);
        $basket->setShipping(1000);

        $breakdown = $this->calculator()->breakdown($basket);

        $this->assertSame(0, $breakdown['amount']);
        $this->assertSame([0, 0], array_column($breakdown['rates'], 'total'));
    }

    private function calculator(): VatCalculator
    {
        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('getContentFlags')->willReturnCallback(
            static fn (array $itemData): int => empty($itemData['giftCard']) ? Basket::CONTENT_FLAG_PHYSICAL : Basket::CONTENT_FLAG_GIFT_CARD
        );

        $registry = $this->createStub(BasketItemProviderRegistry::class);
        $registry->method('get')->willReturn($provider);

        return new VatCalculator($registry);
    }

    /**
     * @param list<array{total: int, vat: float, giftCard?: bool}> $lines
     */
    private function basket(array $lines): Basket
    {
        $items = [];
        foreach ($lines as $index => $line) {
            $items['product'][$index] = [
                'item' => ['vat' => $line['vat']],
                'quantity' => 1,
                'total' => $line['total'],
                'giftCard' => $line['giftCard'] ?? false,
            ];
        }

        $basket = new Basket();
        $basket->setItems($items);

        return $basket;
    }
}
