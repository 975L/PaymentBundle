<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Email;

use c975L\PaymentBundle\Entity\Payment;

/**
 * Interface to be called for DI for Payment Email related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface PaymentEmailInterface
{
    /**
     * Sends emails related to Payment
     */
    public function send(Payment $payment, string $amount);

    /**
     * Sends email for error
     */
    public function sendError(array $errData);

    /**
     * Sends email related to Payment for the user
     */
    public function sendUser(Payment $payment, string $subject);

    /**
     * Sends email related to Payment for the site
     */
    public function sendSite(Payment $payment, string $subject);
}
