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
use c975L\PaymentBundle\Enum\PaymentEvent;
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

        // Only an order taking money in settles a basket - a payment, or a payment request sent from Revolut's own portal. A refund or a chargeback is an order of its own, completed like a payment and announced by the same event: read as one, it would settle the basket again with its id. RevolutGateway reads a refund through readRefund(), and a type Revolut adds later stays ignored until it is known
        if (!\in_array($order['type'] ?? 'payment', ['payment', 'payment_request'], true)) {
            return null;
        }

        $basketId = $this->basketId($order);
        $orderId = $this->orderId($order);

        return new PaymentNotification(
            $basketId,
            RevolutGateway::SLUG,
            $orderId,
            $this->readPaymentMethod($order),
            $this->readAmount($order),
        );
    }

    // Reads the order a refund was taken from and returns the running total handed back so far, partial refunds included - a refund order carries its own amount alone, and whether that total is the whole order is BasketRefundService's call
    /**
     * @param array<string, mixed> $order      the original payment order, as the Merchant API answers it
     * @param bool                 $replayable whether a total still at zero may yet catch up, which RevolutGateway decides from the refund's date
     *
     * @return PaymentNotification|null null when the order counts nothing refunded and a replay would change nothing
     *
     * @throws InvalidNotificationException when the order carries no id to find the basket back with, or no refund yet while it may still come
     */
    public function readRefund(array $order, bool $replayable = true): ?PaymentNotification
    {
        // Revolut warns its events can arrive out of order: an original read before its total caught up with the refund would answer nothing refunded, and the refund would be dropped for good. Refused instead, so Revolut replays it and the next read counts it - for as long as that can still happen
        $refunded = (int) ($order['refunded_amount'] ?? 0);
        if ($refunded <= 0) {
            if ($replayable) {
                throw new InvalidNotificationException('The refunded order says nothing was refunded yet');
            }

            return null;
        }

        return new PaymentNotification(null, RevolutGateway::SLUG, $this->orderId($order), null, $refunded, PaymentEvent::Refunded);
    }

    // Reads a dispute and returns the chargeback it settles when the shop lost it, the basket being found back from the payment order it was raised against
    /**
     * @param array<string, mixed> $dispute as the Merchant API answers it
     *
     * @return PaymentNotification|null null for a dispute not lost, which takes nothing back
     *
     * @throws InvalidNotificationException when a lost dispute names no order
     */
    public function readDispute(array $dispute): ?PaymentNotification
    {
        if ('lost' !== ($dispute['state'] ?? null)) {
            return null;
        }

        $orderId = $dispute['payment']['order_id'] ?? null;
        if (!is_string($orderId) || '' === $orderId) {
            throw new InvalidNotificationException('Order id is missing from the lost dispute');
        }

        return new PaymentNotification(null, RevolutGateway::SLUG, $orderId, null, isset($dispute['amount']) ? (int) $dispute['amount'] : null, PaymentEvent::ChargebackLost);
    }

    // Revolut hands nothing of ours back but the merchant reference, which is what the checkout wrote the basket into; the order fetched from the api names it under the key it was sent with, the webhook under its own
    private function basketId(array $order): string
    {
        $basketId = $order['merchant_order_ext_ref'] ?? $order['merchant_order_data']['reference'] ?? null;
        if (null === $basketId || '' === (string) $basketId) {
            throw new InvalidNotificationException('Basket ID is missing from the merchant reference');
        }

        return (string) $basketId;
    }

    // The order id is what the back-office records of the payment, Revolut charging an order rather than naming a charge of its own
    private function orderId(array $order): string
    {
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId) || '' === $orderId) {
            throw new InvalidNotificationException('Order id is missing from the order');
        }

        return $orderId;
    }

    // An order carries every attempt made on it, a card declined then a wallet accepted included: the method of the one payment that went through is what the customer actually paid with, and null when none did
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
