<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

// To add a payment provider, you need to: create a class implementing this interface in the Gateway folder of your bundle; add its slug to the "choices" of the payment-gateway config entry; PaymentGatewayRegistry will automatically detect it, and the site owner picks it from the back-office. Everything the provider knows about test keys, signatures and event shapes stays behind these five methods - BasketService only ever sees a url to redirect to and a PaymentNotification
interface PaymentGatewayInterface
{
    // Stable identifier of the provider (eg. "stripe", "revolut"), what the payment-gateway config holds and what the webhook route carries.
    public function getSlug(): string;

    // Whether the keys this provider needs are set, test mode included - a gateway that cannot charge anything must say so rather than fail at checkout time.
    public function isConfigured(): bool;

    // Opens a checkout with the provider: where to send the customer, and what that provider calls the checkout by.
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Reads a provider notification, signature verified, and returns the payment it confirms.
     *
     * @return PaymentNotification|null null when the event is authentic but is not a completed payment, the only case the site acts on
     *
     * @throws \c975L\PaymentBundle\Exception\InvalidNotificationException when the payload or its signature does not check out
     */
    public function readNotification(Request $request): ?PaymentNotification;

    // The provider's own back-office page for a transaction, so a paid order links to what charged it; null when the provider offers none.
    public function getTransactionUrl(string $transactionId): ?string;
}
