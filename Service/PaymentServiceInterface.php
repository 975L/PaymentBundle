<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use c975L\PaymentBundle\Entity\Payment;

interface PaymentServiceInterface
{
    //Creates the payment
    public function create($data);

    //Creates flash
    public function createFlash($payment);

    //Creates flash for error
    public function createFlashError($displayError);

    //Creates the charge on Stripe's servers - This will charge the user's card
    public function charge($stripeSession);

    //Get publishable key
    public function getPublishableKey($live = false);

    //Get secret key
    public function getSecretKey($live = false);

    //Re-use a Stripe payment not executed
    public function reUse($payment);

    //Sends emails
    public function sendEmail($payment);

    //Sends an email on error
    public function sendEmailError($errCode, $errMessage);

    //Sends email for user
    public function sendEmailUser($payment);

    //Sends email to the site
    public function sendEmailSite($payment);
}
