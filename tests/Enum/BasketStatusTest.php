<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Enum;

use c975L\PaymentBundle\Enum\BasketStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BasketStatusTest extends TestCase
{
    // Only a paid basket, shipped or not, hands over what was bought
    public function testOnlyPaidAndShippedAreSettled(): void
    {
        $this->assertFalse(BasketStatus::New->isSettled());
        $this->assertFalse(BasketStatus::Validated->isSettled());
        $this->assertTrue(BasketStatus::Paid->isSettled());
        $this->assertTrue(BasketStatus::Shipped->isSettled());
        $this->assertFalse(BasketStatus::Refunded->isSettled());
    }

    // A refunded order is still an order on the books, never a basket to fill again
    public function testRecordedKeepsTheRefundedOrders(): void
    {
        $this->assertFalse(BasketStatus::New->isRecorded());
        $this->assertFalse(BasketStatus::Validated->isRecorded());
        $this->assertTrue(BasketStatus::Paid->isRecorded());
        $this->assertTrue(BasketStatus::Shipped->isRecorded());
        $this->assertTrue(BasketStatus::Refunded->isRecorded());
    }

    // The values are what the column already holds, so no row has to be migrated
    public function testValuesMatchTheStoredStrings(): void
    {
        $this->assertSame(['new', 'validated', 'paid', 'shipped', 'refunded'], array_column(BasketStatus::cases(), 'value'));
    }

    // Each case is translated from the payment domain
    public function testTransUsesThePaymentDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with('label.basket_status_paid', [], 'payment', 'fr')->willReturn('Payé');

        $this->assertSame('Payé', BasketStatus::Paid->trans($translator, 'fr'));
    }
}
