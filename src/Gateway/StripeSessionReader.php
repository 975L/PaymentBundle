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

// The step of the checkout that decides whether a Stripe Checkout Session says "this basket is paid", split out of StripeGateway so it can be exercised on its own: it reaches nothing, holds nothing and answers on the payload alone, where every other step of the gateway needs Stripe on the line
// Both paths read a session through this class - the signed webhook event and the session fetched back when the customer returns - so the two cannot drift into disagreeing about what counts as paid
final class StripeSessionReader
{
    /**
     * Reads a Checkout Session and returns the payment it confirms.
     *
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $session as Stripe sends it, read through ArrayAccess: the webhook carries a bare StripeObject, whose shape is known to Stripe alone
     *
     * @return PaymentNotification|null null when the session is authentic but does not say the money arrived, the only case the site acts on
     *
     * @throws InvalidNotificationException when the session says it is paid but carries none of what the site records of it
     */
    public function read(array | \ArrayAccess $session): ?PaymentNotification
    {
        // "completed" is not "paid": a session settled asynchronously (SEPA debit, bank transfer) completes while the money is still on its way, and the shop would ship against funds that may never arrive. Which methods a site offers is a setting of its Stripe dashboard, so this is asked whatever the checkout was opened with
        if ('paid' !== ($session['payment_status'] ?? null)) {
            return null;
        }

        $basketId = $session['metadata']['basket_id'] ?? null;
        if (null === $basketId || '' === (string) $basketId) {
            throw new InvalidNotificationException('Basket ID is missing from metadata');
        }

        // A paid session names the payment intent that carries the charge, which is what the back-office links to
        $transactionId = $session['payment_intent'] ?? null;
        if (!is_string($transactionId) || '' === $transactionId) {
            throw new InvalidNotificationException('Payment intent is missing from the paid session');
        }

        // The methods the checkout was opened with, the session carrying the same list the payment intent does - reading it here spares a second call to Stripe on every payment
        $paymentMethodTypes = $session['payment_method_types'] ?? null;

        return new PaymentNotification(
            (string) $basketId,
            StripeGateway::SLUG,
            $transactionId,
            is_iterable($paymentMethodTypes) ? ((array) $paymentMethodTypes)[0] ?? null : null,
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
        );
    }
}
