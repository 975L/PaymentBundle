<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Gateway;

use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Exception\InvalidNotificationException;

// The step of the checkout that decides whether a Revolut order says "this basket is paid", split out of RevolutGateway so it can be exercised on its own: it reaches nothing, holds nothing and answers on the payload alone, where every other step of the gateway needs Revolut on the line
// Both paths read an order through this class - the one the signed webhook names and the one fetched back when the customer returns - so the two cannot drift into disagreeing about what counts as paid
final class RevolutOrderReader
{
    /**
     * Reads an order as the Merchant API answers it and returns the payment it confirms.
     *
     * @param array<string, mixed> $order
     *
     * @return PaymentNotification|null null when the order is authentic but does not say the money arrived, the only case the site acts on
     *
     * @throws InvalidNotificationException when the order says it is paid but carries none of what the site records of it
     */
    public function read(array $order): ?PaymentNotification
    {
        // "completed" is the only state saying the money arrived: an order sits at "authorised" while the funds are merely held for a capture nobody has asked for yet, and at "processing" while a method settling later is still on its way - a shop delivering on either ships against money it may never see
        if ('completed' !== ($order['state'] ?? null)) {
            return null;
        }

        // Revolut hands nothing of ours back but the merchant reference, which is what the checkout wrote the basket into; the order fetched from the api names it under the key it was sent with, the webhook under its own
        $basketId = $order['merchant_order_ext_ref'] ?? $order['merchant_order_data']['reference'] ?? null;
        if (null === $basketId || '' === (string) $basketId) {
            throw new InvalidNotificationException('Basket ID is missing from the merchant reference');
        }

        // The order id is what the back-office records of the payment, Revolut charging an order rather than naming a charge of its own
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId) || '' === $orderId) {
            throw new InvalidNotificationException('Order id is missing from the completed order');
        }

        return new PaymentNotification(
            (string) $basketId,
            RevolutGateway::SLUG,
            $orderId,
            $this->readPaymentMethod($order),
            $this->readAmount($order),
        );
    }

    // An order carries every attempt made on it, a card declined then a wallet accepted included: the one that went through is what the customer actually paid with
    private function readPaymentMethod(array $order): ?string
    {
        foreach ($order['payments'] ?? [] as $payment) {
            if ('completed' === ($payment['state'] ?? null)) {
                $type = $payment['payment_method']['type'] ?? null;

                return is_string($type) ? $type : null;
            }
        }

        return null;
    }

    // The amount sits at the root of the order in the api version this bundle pins, and under "order_amount" in the versions around it - read wherever it is rather than left null, the checkout matching it against what the basket was worth before it delivers anything
    private function readAmount(array $order): ?int
    {
        $amount = $order['amount'] ?? $order['order_amount']['value'] ?? null;

        return is_numeric($amount) ? (int) $amount : null;
    }
}
