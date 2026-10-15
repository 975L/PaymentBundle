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
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Gateway\RevolutGateway;
use c975L\PaymentBundle\Gateway\RevolutOrderReader;
use PHPUnit\Framework\TestCase;

// What a Revolut order has to say for a basket to be delivered, decided on the payload alone
class RevolutOrderReaderTest extends TestCase
{
    public function testACompletedOrderConfirmsThePayment(): void
    {
        $notification = new RevolutOrderReader()->read($this->order());

        $this->assertNotNull($notification);
        $this->assertSame('42', $notification->basketId);
        $this->assertSame(RevolutGateway::SLUG, $notification->gateway);
        $this->assertSame('9fc01989', $notification->transactionId);
        $this->assertSame('card', $notification->paymentMethod);
        $this->assertSame(2500, $notification->amount);
    }

    // A refund completes as an order of its own, and read as a payment it would settle the basket again with the refund's id
    public function testACompletedRefundOrderConfirmsNoPayment(): void
    {
        $this->assertNull(new RevolutOrderReader()->read($this->order(['type' => 'refund'])));
    }

    // A payment request sent from Revolut's own portal takes money in like a checkout order does
    public function testACompletedPaymentRequestConfirmsThePayment(): void
    {
        $this->assertSame('42', new RevolutOrderReader()->read($this->order(['type' => 'payment_request']))?->basketId);
    }

    // A type Revolut adds later is ignored until it is known, rather than read as money coming in
    public function testAnUnknownOrderTypeConfirmsNoPayment(): void
    {
        $this->assertNull(new RevolutOrderReader()->read($this->order(['type' => 'credit_reimbursement'])));
    }

    // The original order carries the running total of what was refunded, partial refunds included
    public function testARefundIsReadOffTheOriginalOrder(): void
    {
        $notification = new RevolutOrderReader()->readRefund(['id' => '9fc01989', 'refunded_amount' => 500]);

        $this->assertNotNull($notification);
        $this->assertNull($notification->basketId);
        $this->assertSame('9fc01989', $notification->transactionId);
        $this->assertSame(500, $notification->amount);
        $this->assertSame(PaymentEvent::Refunded, $notification->event);
    }

    // An original read before its total caught up is refused, so Revolut replays the event rather than the refund being dropped
    public function testAnOriginalWithNothingRefundedYetIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        new RevolutOrderReader()->readRefund(['id' => '9fc01989', 'refunded_amount' => 0]);
    }

    // Once a replay can no longer bring the total up, the refund is dropped rather than refused again
    public function testAnOriginalWithNothingRefundedPastTheReplayWindowIsDropped(): void
    {
        $this->assertNull(new RevolutOrderReader()->readRefund(['id' => '9fc01989', 'refunded_amount' => 0], false));
    }

    // Only a lost dispute takes money back, and it names the payment order it was raised against
    public function testALostDisputeIsAChargeback(): void
    {
        $reader = new RevolutOrderReader();
        $dispute = ['state' => 'lost', 'amount' => 2500, 'payment' => ['order_id' => '9fc01989']];

        $notification = $reader->readDispute($dispute);

        $this->assertSame('9fc01989', $notification?->transactionId);
        $this->assertSame(PaymentEvent::ChargebackLost, $notification?->event);
        foreach (['needs_response', 'under_review', 'won'] as $state) {
            $this->assertNull($reader->readDispute(['state' => $state] + $dispute), $state);
        }
    }

    public function testALostDisputeNamingNoOrderIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        new RevolutOrderReader()->readDispute(['state' => 'lost']);
    }

    // An order sits at "authorised" while the funds are only held, and a shop delivering on that ships against a capture nobody has asked for
    public function testAnOrderThatIsNotCompletedConfirmsNothing(): void
    {
        foreach (['pending', 'processing', 'authorised', 'cancelled', 'failed'] as $state) {
            $this->assertNull(new RevolutOrderReader()->read($this->order(['state' => $state])), $state);
        }
    }

    // The merchant reference is the only thing of the site's that Revolut hands back, and an order carrying none settles no basket
    public function testACompletedOrderWithoutAMerchantReferenceIsRefused(): void
    {
        $order = $this->order();
        unset($order['merchant_order_ext_ref']);

        $this->expectException(InvalidNotificationException::class);

        new RevolutOrderReader()->read($order);
    }

    // The order fetched from the api names the reference under the key it was sent with, where the webhook names it under its own
    public function testTheReferenceIsAlsoReadWhereTheApiAnswersIt(): void
    {
        $order = $this->order();
        unset($order['merchant_order_ext_ref']);
        $order['merchant_order_data'] = ['reference' => '42'];

        $this->assertSame('42', new RevolutOrderReader()->read($order)?->basketId);
    }

    // The order id is what the back-office records of the payment, and one recorded against nothing links nowhere
    public function testACompletedOrderWithoutAnIdIsRefused(): void
    {
        $order = $this->order();
        unset($order['id']);

        $this->expectException(InvalidNotificationException::class);

        new RevolutOrderReader()->read($order);
    }

    // The versions around the one the gateway pins carry the amount under "order_amount", and an amount left null is one the checkout cannot match against the basket
    public function testTheAmountIsReadWhereverTheVersionCarriesIt(): void
    {
        $order = $this->order();
        unset($order['amount']);
        $order['order_amount'] = ['value' => 2500, 'currency' => 'EUR'];

        $this->assertSame(2500, new RevolutOrderReader()->read($order)?->amount);
    }

    // An order carries every attempt made on it, a card declined then a wallet accepted included
    public function testThePaymentMethodIsTheOneThatWentThrough(): void
    {
        $order = $this->order(['payments' => [
            ['state' => 'failed', 'payment_method' => ['type' => 'card']],
            ['state' => 'completed', 'payment_method' => ['type' => 'revolut_pay']],
        ]]);

        $this->assertSame('revolut_pay', new RevolutOrderReader()->read($order)?->paymentMethod);
    }

    // An order completed with no payment of its own answering for it names no method, rather than the first attempt made on it
    public function testNoCompletedPaymentNamesNoMethod(): void
    {
        $order = $this->order(['payments' => [
            ['state' => 'failed', 'payment_method' => ['type' => 'card']],
        ]]);

        $this->assertNull(new RevolutOrderReader()->read($order)?->paymentMethod);
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => '9fc01989',
            'state' => 'completed',
            'amount' => 2500,
            'currency' => 'EUR',
            'merchant_order_ext_ref' => '42',
            'payments' => [['state' => 'completed', 'payment_method' => ['type' => 'card']]],
        ];
    }
}
