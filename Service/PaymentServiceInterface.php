<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Entity\Payment;

/**
 * Interface to be called for DI for PaymentServiceInterface related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface PaymentServiceInterface
{
    /**
     * Charges the payment server
     * @return string|false
     */
    public function charge(string $service, Payment $paymentSession);

    /**
     * Creates the payment
     */
    public function create(array $paymentData);

    /**
     * Shortcut to call PaymentFormFactory to create Form
     * @return Form
     */
    public function createForm(string $name, Payment $payment);

    /**
     * Creates the payment for a free amount
     */
    public function createFreeAmount(Payment $payment);

    /**
     * Creates the payment for a defined amount
     */
    public function createDefinedAmount($user, string $text, int $amount, string $currency);

    /**
     * Defines the data to use for a free amount Payment
     * @return array
     */
    public function defineFreeAmount($user);

    /**
     * An error has occured after the Payment (Sends email to site + flash)
     */
    public function error(Payment $payment);

    /**
     * Gets all the Payment
     * @return mixed
     */
    public function getAll();

    /**
     * Gets the Payment from session
     * @return Payment|null
     */
    public function getFromSession(string $kind);

    /**
     * Returns the value of parameter
     * @return mixed
     * @throws \LogicException
     */
    public function getParameter(string $parameter);

    /**
     * Registers the Payment in DB + Session
     */
    public function register(Payment $payment);

    /**
     * Defines PaymentData from session
     * @return array|null
     */
    public function setDataFromSession(string $kind);
}
