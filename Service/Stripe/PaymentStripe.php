<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Stripe;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Payment;
use Exception;
use Stripe\Card;
use Stripe\Charge;
use Stripe\Error\ApiConnection;
use Stripe\Error\Authentication;
use Stripe\Error\Base;
use Stripe\Error\InvalidRequest;
use Stripe\Error\RateLimit;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Exception\InvalidArgumentException;

/**
 * PaymentStripe class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentStripe implements PaymentStripeInterface
{
    /**
     * Stores ConfigServiceInterface
     * @var ConfigServiceInterface
     */
    private $configService;

    /**
     * Stores current Request
     * @var Request
     */
    private $request;

    public function __construct(
        ConfigServiceInterface $configService,
        RequestStack $requestStack
    )
    {
        $this->configService = $configService;
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
            Stripe::setApiKey($this->getSecretKey($payment->getLive()));
            Charge::create($stripePaymentData);

            //Updates data for payment done
            $payment
                ->setStripeFee((int) (($payment->getAmount() * $this->configService->getParameter('c975LPayment.stripeFeePercentage') / 100) + $this->configService->getParameter('c975LPayment.stripeFeeFixed')))
                ->setStripeToken($this->request->get('stripeToken'))
                ->setStripeTokenType($this->request->get('stripeTokenType'))
                ->setStripeEmail($this->request->get('stripeEmail'))
            ;

            return true;
        //Errors
        } catch (Card $e) {
            //Since it's a decline, \Stripe\Error\Card will be caught
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe01 - Card';
            $display = true;
        } catch (RateLimit $e) {
            //Too many requests made to the API too quickly
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe02 - RateLimit';
            $display = true;
        } catch (InvalidRequest $e) {
            //Invalid parameters were supplied to Stripe's API
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe03 - InvalidRequest';
            $display = false;
        } catch (Authentication $e) {
            //Authentication with Stripe's API failed (maybe you changed API keys recently)
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe04 - Authentication';
            $display = false;
        } catch (ApiConnection $e) {
            //Network communication with Stripe failed
            $message = $e->getJsonBody()['error']['message'];
            $code = 'ErrStripe05 - ApiConnection';
            $display = true;
        } catch (Base $e) {
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
        $stripePublishableKey = $live ? $this->configService->getParameter('c975LPayment.stripePublishableKeyLive') : $this->configService->getParameter('c975LPayment.stripePublishableKeyTest');

        if (null !== $stripePublishableKey) {
            return $stripePublishableKey;
        }

        if ($live) {
            throw new InvalidArgumentException('The stripePublishableKeyLive has not been set');
        }

        throw new InvalidArgumentException('The stripePublishableKeyTest has not been set');
    }

    /**
     * {@inheritdoc}
     */
    public function getSecretKey(bool $live = false)
    {
        $stripeSecretKey = $live ? $this->configService->getParameter('c975LPayment.stripeSecretKeyLive') : $this->configService->getParameter('c975LPayment.stripeSecretKeyTest');

        if (null !== $stripeSecretKey) {
            return $stripeSecretKey;
        }

        if ($live) {
            throw new InvalidArgumentException('The stripeSecretKeyLive has not been set');
        }

        throw new InvalidArgumentException('The stripeSecretKeyTest has not been set');
    }
}
