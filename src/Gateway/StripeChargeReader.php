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

// Decides whether a Stripe charge or dispute takes money back, split out of StripeGateway like StripeSessionReader to answer on the payload alone. The charge never names the basket, so both answers carry the payment intent the session recorded
final class StripeChargeReader
{
    // Reads a refunded charge and returns the running total handed back so far, Stripe sending one on every refund, partial ones included
    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $charge as Stripe sends it
     *
     * @return PaymentNotification|null null for a charge no Checkout Session of this site took, which carries no payment intent to find an order with
     */
    public function readRefund(array | \ArrayAccess $charge): ?PaymentNotification
    {
        $paymentIntent = $charge['payment_intent'] ?? null;
        if (!is_string($paymentIntent) || '' === $paymentIntent) {
            return null;
        }

        return new PaymentNotification(null, StripeGateway::SLUG, $paymentIntent, null, (int) ($charge['amount_refunded'] ?? 0), PaymentEvent::Refunded);
    }

    // Reads a closed dispute and returns the chargeback it settles: only "lost" takes the money back, and the close is read rather than the opening
    /** @param array<string, mixed>|\ArrayAccess<string, mixed> $dispute as Stripe sends it */
    public function readDispute(array | \ArrayAccess $dispute): ?PaymentNotification
    {
        $paymentIntent = $dispute['payment_intent'] ?? null;
        if ('lost' !== ($dispute['status'] ?? null) || !is_string($paymentIntent) || '' === $paymentIntent) {
            return null;
        }

        return new PaymentNotification(null, StripeGateway::SLUG, $paymentIntent, null, isset($dispute['amount']) ? (int) $dispute['amount'] : null, PaymentEvent::ChargebackLost);
    }
}
