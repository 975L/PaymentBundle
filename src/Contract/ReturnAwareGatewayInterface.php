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

// Implemented, on top of PaymentGatewayInterface, by a provider able to confirm a payment when the customer comes back to the site, rather than only through its webhook. Kept apart from PaymentGatewayInterface on purpose, exactly like VerifiableGatewayInterface: a provider offering no such call stays valid and is simply confirmed by its webhook alone
// The two paths back each other up - the webhook covers the customer who closes the tab, this one covers the site whose webhook endpoint is misconfigured - and both end up in the same BasketService::paid(), which delivers nothing until a payment is recorded as finished
interface ReturnAwareGatewayInterface
{
    /**
     * Reads the customer's return to the site and asks the provider itself whether that payment went through.
     *
     * The url the customer comes back on proves nothing - it is handed to them before they pay - so what it
     * carries is only ever used to look the payment up at the provider, never as the confirmation itself.
     *
     * @param string|null $reference what the provider called the checkout when it was opened, kept on the
     *                               payment since. A provider writing nothing of its own into the return url
     *                               has this and nothing else to look the payment up by; null when the
     *                               checkout was already settled or called off
     *
     * @return PaymentNotification|null null when the return carries nothing to look up, or when the provider
     *                                  answers that the payment is not settled
     */
    public function readReturn(Request $request, ?string $reference): ?PaymentNotification;
}
