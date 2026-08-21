<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Implemented, on top of PaymentGatewayInterface, by a provider whose open checkout can be called off. Kept apart on purpose, like VerifiableGatewayInterface and ReturnAwareGatewayInterface: a provider offering no such call stays valid
// A checkout stays payable at the provider until it is paid or times out, and the customer who edits their basket still has it open in a tab. Without this the site is left refusing the payment after the fact - the customer is charged and takes delivery of nothing; with it, the stale checkout stops being payable the moment the basket it priced is edited
interface ExpirableGatewayInterface
{
    /**
     * Calls off a checkout the customer has not paid, so it can no longer be paid.
     *
     * @param string $reference what the provider called that checkout, as CheckoutSession carried it back
     *
     * @throws \Exception when the provider refuses or cannot be reached - a checkout already paid, already
     *                    expired or unknown is the provider's answer, not the caller's to guess
     */
    public function expireCheckout(string $reference): void;
}
