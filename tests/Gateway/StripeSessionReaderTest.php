<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Gateway;

use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Gateway\StripeSessionReader;
use PHPUnit\Framework\TestCase;

// The one step of the checkout that says whether Stripe reports the money as arrived - exercised on the payload alone, which is the whole point of it being a class of its own
class StripeSessionReaderTest extends TestCase
{
    // The step that turns a settled session into what the site records of it
    public function testAPaidSessionIsReadIntoItsNotification(): void
    {
        $notification = new StripeSessionReader()->read($this->session());

        $this->assertNotNull($notification);
        $this->assertSame('42', $notification->basketId);
        $this->assertSame('stripe', $notification->gateway);
        $this->assertSame('pi_1', $notification->transactionId);
        $this->assertSame('card', $notification->paymentMethod);
        $this->assertSame(2500, $notification->amount);
    }

    // "checkout.session.completed" fires on a SEPA debit or a bank transfer while the money is still on its way: delivering there ships against funds that may never arrive
    public function testASessionCompletedButNotPaidConfirmsNothing(): void
    {
        $this->assertNull(new StripeSessionReader()->read($this->session(['payment_status' => 'unpaid'])));
    }

    public function testASessionAwaitingPaymentConfirmsNothing(): void
    {
        $this->assertNull(new StripeSessionReader()->read($this->session(['payment_status' => 'no_payment_required'])));
    }

    // Without the basket id the site cannot tell which order was paid, and guessing one would deliver somebody else's
    public function testAPaidSessionNamingNoBasketIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        new StripeSessionReader()->read($this->session(['metadata' => []]));
    }

    public function testAPaidSessionWithoutItsPaymentIntentIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        new StripeSessionReader()->read($this->session(['payment_intent' => null]));
    }

    // A gateway that reports no amount leaves the check to the basket alone rather than blocking the payment
    public function testAnAmountlessSessionIsStillRead(): void
    {
        $notification = new StripeSessionReader()->read($this->session(['amount_total' => null]));

        $this->assertNotNull($notification);
        $this->assertNull($notification->amount);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function session(array $overrides = []): array
    {
        return array_replace([
            'payment_status' => 'paid',
            'payment_intent' => 'pi_1',
            'payment_method_types' => ['card'],
            'amount_total' => 2500,
            'metadata' => ['basket_id' => '42', 'order_number' => '202608-AB-12345'],
        ], $overrides);
    }
}
