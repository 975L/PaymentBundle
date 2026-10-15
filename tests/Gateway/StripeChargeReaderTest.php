<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Gateway;

use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Gateway\StripeChargeReader;
use c975L\PaymentBundle\Gateway\StripeGateway;
use PHPUnit\Framework\TestCase;

// What a Stripe charge or dispute has to say for money to go back, decided on the payload alone
class StripeChargeReaderTest extends TestCase
{
    // The running total is carried, partial refunds included: whether it is the whole order is BasketService's call
    public function testARefundedChargeCarriesTheTotalRefunded(): void
    {
        $notification = new StripeChargeReader()->readRefund(['payment_intent' => 'pi_1', 'amount' => 2500, 'amount_refunded' => 500, 'refunded' => false]);

        $this->assertNull($notification?->basketId);
        $this->assertSame(StripeGateway::SLUG, $notification?->gateway);
        $this->assertSame('pi_1', $notification?->transactionId);
        $this->assertSame(500, $notification?->amount);
        $this->assertSame(PaymentEvent::Refunded, $notification?->event);
    }

    // A charge no Checkout Session took carries no payment intent, and no order of this site to find
    public function testAChargeWithoutAPaymentIntentIsNotOurs(): void
    {
        $this->assertNull(new StripeChargeReader()->readRefund(['amount_refunded' => 500]));
    }

    // Only a lost dispute takes the money back; one won, or still argued, leaves the order as it stood
    public function testOnlyALostDisputeIsAChargeback(): void
    {
        $reader = new StripeChargeReader();
        $dispute = ['payment_intent' => 'pi_1', 'amount' => 2500];

        $notification = $reader->readDispute(['status' => 'lost'] + $dispute);

        $this->assertSame('pi_1', $notification?->transactionId);
        $this->assertSame(2500, $notification?->amount);
        $this->assertSame(PaymentEvent::ChargebackLost, $notification?->event);
        foreach (['won', 'warning_closed', 'needs_response', 'under_review'] as $status) {
            $this->assertNull($reader->readDispute(['status' => $status] + $dispute), $status);
        }
    }
}
