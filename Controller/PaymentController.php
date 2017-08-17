<?php
/*
 * (c) 2017: 975l <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;
use c975L\PaymentBundle\Entity\StripePayment;

class PaymentController extends Controller
{
//PAYMENT FORM
    /**
     * @Route("/payment",
     *      name="payment_display")
     * @Method({"GET", "HEAD"})
     */
    public function displayAction(Request $request)
    {
        //Grabs data from session
        $stripePayment = $request->getSession()->get('stripe');

        //Defines payment
        if ($stripePayment instanceof StripePayment) {
            //Stripe key - Tests payments
            if ($this->getParameter('c975_l_payment.live') === false) {
                if (!$this->container->hasParameter('stripe_publishable_key_test')) {
                    throw new InvalidArgumentException();
                }
                $stripePublishableKey = $this->getParameter('stripe_publishable_key_test');
                $test = true;
            //Stripe key - Live payments
            } else {
                if (!$this->container->hasParameter('stripe_publishable_key_live')) {
                    throw new InvalidArgumentException();
                }
                $stripePublishableKey = $this->getParameter('stripe_publishable_key_live');
                $test = false;
            }

            //Renders the payment
            return $this->render('@c975LPayment/pages/payment.html.twig', array(
                'key' => $stripePublishableKey,
                'site' => $this->getParameter('c975_l_payment.site'),
                'image' => $this->getParameter('c975_l_payment.image'),
                'zipCode' => $this->getParameter('c975_l_payment.zipCode') === true ? 'true' : 'false',
                'alipay' => $this->getParameter('c975_l_payment.alipay') === true ? 'true' : 'false',
                'bitcoin' => $this->getParameter('c975_l_payment.bitcoin') === true ? 'true' : 'false',
                'test' => $test,
                'payment' => $stripePayment,
                ));
        }

        //No current payment
        return $this->render('@c975LPayment/pages/noPayment.html.twig');
    }

//CHARGE
    /**
     * @Route("/payment-charge",
     *      name="payment_charge")
     * @Method({"GET", "POST", "HEAD"})
     */
    public function chargeAction(Request $request)
    {
        //Gets the translator
        $translator = $this->get('translator');

        //Grabs data from transaction
        $stripeToken = $request->get('stripeToken');
        $stripeTokenType = $request->get('stripeTokenType');
        $stripeEmail = $request->get('stripeEmail');

        //Grabs data from session
        $session = $request->getSession();
        $stripeSession = $session->get('stripe');

        if (!$stripeSession instanceof StripePayment) {
            throw $this->createNotFoundException();
        }

        //Defines payment
        $payment = array(
            'amount' => $stripeSession->getAmount(),
            'currency' => $stripeSession->getCurrency(),
            'source' => $stripeToken,
            'description' => $stripeSession->getDescription(),
            'metadata' => array('order_id' => $stripeSession->getOrderId())
        );

        //Creates the charge on Stripe's servers - This will charge the user's card
        try {
            //Stripe key - Tests payments
            if ($this->getParameter('c975_l_payment.live') === false) {
                if (!$this->container->hasParameter('stripe_secret_key_test')) {
                    throw new InvalidArgumentException();
                }
                $stripeSecretKey = $this->getParameter('stripe_secret_key_test');
            //Stripe key - Live payments
            } else {
                if (!$this->container->hasParameter('stripe_secret_key_live')) {
                    throw new InvalidArgumentException();
                }
                $stripeSecretKey = $this->getParameter('stripe_secret_key_live');
            }

            //Do the Stripe transaction
            \Stripe\Stripe::setApiKey($stripeSecretKey);
            $charge = \Stripe\Charge::create($payment);

            //Deletes data from session
            $session->remove('stripe');

            //Gets the manager
            $em = $this->getDoctrine()->getManager();

            //Updates stripePayment
            $stripePayment = $em->getRepository('c975L\PaymentBundle\Entity\StripePayment')
                ->findOneByOrderId($stripeSession->getOrderId());
            if ($stripePayment instanceof StripePayment) {
                $stripePayment->setStripeFee((int) (($stripePayment->getAmount() * 1.4 / 100) + 25));
                $stripePayment->setStripeToken($stripeToken);
                $stripePayment->setStripeTokenType($stripeTokenType);
                $stripePayment->setStripeEmail($stripeEmail);

                //Persist in DB
                $em->persist($stripePayment);
                $em->flush();
            }

            //Gets emailService
            $emailService = $this->get(\c975L\EmailBundle\Service\EmailService::class);

            //Creates email for user
            $subject = $this->getParameter('c975_l_payment.site');
            $subject .= ' - ' . $translator->trans('label.payment_done', array(), 'payment');
            $subject .= ' (' . $stripePayment->getAmount() / 100 . ' ' . $stripePayment->getCurrency() . ')';
            $body = $this->renderView('@c975LPayment/emails/paymentDone.html.twig', array(
                'locale' => $request->getLocale(),
                'payment' => $stripePayment,
                'email' => $this->getParameter('c975_l_email.sentFrom'),
                'site' => $this->getParameter('c975_l_payment.site'),
                'stripeFee' => false,
                ));
            $emailData = array(
                'subject' => $subject,
                'sentFrom' => $this->getParameter('c975_l_email.sentFrom'),
                'sentTo' => $stripeEmail,
                'body' => $body,
                'ip' => $request->getClientIp(),
                );
            $emailService->send($emailData, $this->getParameter('c975_l_payment.database'));

            //Creates email for site
            $body = $this->renderView('@c975LPayment/emails/paymentDone.html.twig', array(
                'locale' => $request->getLocale(),
                'payment' => $stripePayment,
                'email' => $this->getParameter('c975_l_email.sentFrom'),
                'site' => $this->getParameter('c975_l_payment.site'),
                'stripeFee' => true,
                ));
            $emailData = array(
                'subject' => $subject,
                'sentFrom' => $this->getParameter('c975_l_email.sentFrom'),
                'sentTo' => $this->getParameter('c975_l_email.sentFrom'),
                'body' => $body,
                'ip' => $request->getClientIp(),
                );
            $emailService->send($emailData, $this->getParameter('c975_l_payment.database'));

            //Creates flash
            $flash = $translator->trans('label.payment_done', array(), 'payment');
            $flash .= ' (' . $stripePayment->getAmount() / 100 . ' ' . $stripePayment->getCurrency() . ')';
            $session->getFlashBag()->add('success', $flash);

            //Redirects to returnRoute
            return $this->redirectToRoute($this->getParameter('c975_l_payment.returnRoute'), array('orderId' => $stripePayment->getOrderId()));

        //Errors
        } catch (\Stripe\Error\Card $e) {
            //Since it's a decline, \Stripe\Error\Card will be caught
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe01';
            $displayError = true;
        } catch (\Stripe\Error\RateLimit $e) {
            //Too many requests made to the API too quickly
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe02';
            $displayError = true;
        } catch (\Stripe\Error\InvalidRequest $e) {
            //Invalid parameters were supplied to Stripe's API
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe03';
            $displayError = false;
        } catch (\Stripe\Error\Authentication $e) {
            //Authentication with Stripe's API failed (maybe you changed API keys recently)
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe04';
            $displayError = false;
        } catch (\Stripe\Error\ApiConnection $e) {
            //Network communication with Stripe failed
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe05';
            $displayError = true;
        } catch (\Stripe\Error\Base $e) {
            //Display a very generic error to the user, and maybe send yourself an email
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe06';
            $displayError = false;
        } catch (Exception $e) {
            //Something else happened, completely unrelated to Stripe
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe07';
            $displayError = false;
        }

        //Sends an email on error
        $errMessage = 'Stripe err : ' . $errMessage;
        $body = $this->renderView('@c975LPayment/emails/errorStripe.html.twig', array(
            'locale' => 'fr',
            'errCode' => $errCode,
            'errMessage' => $errMessage,
            'email' => $this->getParameter('c975_l_email.sentFrom'),
            'site' => $this->getParameter('c975_l_payment.site'),
            ));
        $emailData = array(
            'subject' => 'StripeError : ' . $errCode,
            'sentFrom' => $this->getParameter('c975_l_email.sentFrom'),
            'sentTo' => $this->getParameter('c975_l_email.sentFrom'),
            'body' => $body,
            'ip' => $request->getClientIp(),
            );
        $emailService = $this->get(\c975L\EmailBundle\Service\EmailService::class);
        $emailService->send($emailData, $this->getParameter('c975_l_payment.database'));

        //Creates flash
        if ($displayError === true) {
            $flash = $translator->trans('text.error_payment', array(), 'payment');
            $session->getFlashBag()->add('danger', $flash);
            $session->getFlashBag()->add('danger', $errMessage);
        } else {
            $flash = $translator->trans('text.error_payment_generic', array(), 'payment');
            $session->getFlashBag()->add('danger', $flash);
        }

        //Redirects to payment
        return $this->redirectToRoute('payment_display');
    }
}