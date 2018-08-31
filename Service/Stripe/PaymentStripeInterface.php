<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Stripe;

use c975L\PaymentBundle\Entity\Payment;

/**
 * Interface to be called for DI for PaymentStripeInterface related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface PaymentStripeInterface
{
    /**
     * Charges the payment to Stripe server - This will charge the user's card!
     * @return true|array
     */
    public function charge(Payment $payment);

    /**
     * Gets the Stripe publishable key
     * @return string
     */
    public function getPublishableKey(bool $live = false);

    /**
     * Gets the Stripe Secret key
     * @return string
     */
    public function getSecretKey(bool $live = false);
}
