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
