<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Stripe;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\PaymentServiceInterface;
use c975L\PaymentBundle\Service\Stripe\PaymentStripeInterface;

/**
 * PaymentStripe class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentStripe implements PaymentStripeInterface
{
    /**
     * Stores container
     * @var ContainerInterface
     */
    private $container;

    /**
     * Stores current Request
     * @var RequestStack
     */
    private $request;

    public function __construct(
        ContainerInterface $container,
        RequestStack $requestStack
    )
    {
        $this->container = $container;
        $this->request = $requestStack->getCurrentRequest();
    }

    /**
     * {@inheritdoc}
     */
    public function charge(Payment $payment)
    {
        //Defines Stripe payment data
        $stripePaymentData = array(
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'source' => $this->request->get('stripeToken'),
            'description' => $payment->getDescription(),
            'metadata' => array('order_id' => $payment->getOrderId())
        );

        try {
            //Do the Stripe transaction
            \Stripe\Stripe::setApiKey($this->getSecretKey($payment->getLive()));
            \Stripe\Charge::create($stripePaymentData);

            //Updates data for payment done
            $payment
                ->setStripeFee((int) (($payment->getAmount() * $this->container->getParameter('c975_l_payment.stripeFeePercentage') / 100) + $this->container->getParameter('c975_l_payment.stripeFeeFixed')))
                ->setStripeToken($this->request->get('stripeToken'))
                ->setStripeTokenType($this->request->get('stripeTokenType'))
                ->setStripeEmail($this->request->get('stripeEmail'))
            ;

            return true;
        //Errors
        } catch (\Stripe\Error\Card $e) {
            //Since it's a decline, \Stripe\Error\Card will be caught
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe01 - Card';
            $display = true;
        } catch (\Stripe\Error\RateLimit $e) {
            //Too many requests made to the API too quickly
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe02 - RateLimit';
            $display = true;
        } catch (\Stripe\Error\InvalidRequest $e) {
            //Invalid parameters were supplied to Stripe's API
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe03 - InvalidRequest';
            $display = false;
        } catch (\Stripe\Error\Authentication $e) {
            //Authentication with Stripe's API failed (maybe you changed API keys recently)
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe04 - Authentication';
            $display = false;
        } catch (\Stripe\Error\ApiConnection $e) {
            //Network communication with Stripe failed
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe05 - ApiConnection';
            $display = true;
        } catch (\Stripe\Error\Base $e) {
            //Display a very generic error to the user, and maybe send yourself an email
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe06 - Base';
            $display = false;
        } catch (Exception $e) {
            //Something else happened, completely unrelated to Stripe
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe07 - Other';
            $display = false;
        }

        //Returns error message
        return array(
            'code' => $code,
            'message' => $message,
            'display' => $display,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPublishableKey(bool $live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_publishable_key_test')) {
                throw new InvalidArgumentException('No stripe_publishable_key_test');
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_test');
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_publishable_key_live')) {
                throw new InvalidArgumentException('No stripe_publishable_key_live');
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_live');
        }

        return $stripePublishableKey;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecretKey(bool $live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_secret_key_test')) {
                throw new InvalidArgumentException('No stripe_secret_key_test');
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_test');
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_secret_key_live')) {
                throw new InvalidArgumentException('No stripe_secret_key_live');
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_live');
        }

        return $stripeSecretKey;
    }
}
