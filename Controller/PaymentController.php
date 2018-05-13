<?php
/*
 * (c) 2017: 975L <contact@975l.com>
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
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Form\PaymentType;

class PaymentController extends Controller
{
//DASHBOARD
    /**
     * @Route("/payment/dashboard",
     *      name="payment_dashboard")
     * @Method({"GET", "HEAD"})
     */
    public function dashboardAction(Request $request)
    {
        //Gets the user
        $user = $this->getUser();

        //Returns the dashboard content
        if ($user !== null && $this->get('security.authorization_checker')->isGranted($this->getParameter('c975_l_payment.roleNeeded'))) {
            //Gets the manager
            $em = $this->getDoctrine()->getManager();

            //Gets repository
            $repository = $em->getRepository('c975LPaymentBundle:Payment');

            //Pagination
            $paginator  = $this->get('knp_paginator');
            $pagination = $paginator->paginate(
                $repository->findAll(array(), array('id' => 'DESC')),
                $request->query->getInt('p', 1),
                50
            );

            //Returns the dashboard
            return $this->render('@c975LPayment/pages/dashboard.html.twig', array(
                'payments' => $pagination,
            ));
        }

        //Access is denied
        throw $this->createAccessDeniedException();
    }

//DISPLAY
    /**
     * Kept for retro-compatibility (20/03/2018)
     * @Route("/payment/confirm/{orderId}",
     *      name="payment_confirm",
     *      requirements={"orderId": "^[0-9\-]+$"})
     * @Method({"GET", "HEAD"})
     */
    public function redirectDisplayAction(Request $request, $orderId)
    {
        //Redirects to the display
        return $this->redirectToRoute('payment_display', array(
            'orderId' => $orderId,
        ));
    }
    /**
     * @Route("/payment/{orderId}",
     *      name="payment_display",
     *      requirements={"orderId": "^[0-9\-]+$"})
     * @Method({"GET", "HEAD"})
     */
    public function displayAction(Request $request, $orderId)
    {
        //Gets the manager
        $em = $this->getDoctrine()->getManager();

        //Gets repository
        $repository = $em->getRepository('c975L\PaymentBundle\Entity\Payment');

        //Loads from DB
        $payment = $repository->findOneByOrderId($orderId);

        //Not existing payment
        if (!$payment instanceof Payment) {
            throw $this->createNotFoundException();
        }

        return $this->render('@c975LPayment/pages/display.html.twig', array(
            'payment' => $payment,
            'siteName' => $this->getParameter('c975_l_payment.site'),
        ));
    }

//FORM
    /**
     * @Route("/payment",
     *      name="payment_form")
     * @Method({"GET", "HEAD"})
     */
    public function formAction(Request $request)
    {
        //Grabs data from session
        $payment = $request->getSession()->get('stripe');

        //Defines payment
        if ($payment instanceof Payment) {
            //Gets paymentService
            $paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);

            //Gets the publishable key
            $stripePublishableKey = $paymentService->getPublishableKey($payment->getLive());

            //Renders the payment
            return $this->render('@c975LPayment/pages/payment.html.twig', array(
                'key' => $stripePublishableKey,
                'site' => $this->getParameter('c975_l_payment.site'),
                'image' => $this->getParameter('c975_l_payment.image'),
                'zipCode' => $this->getParameter('c975_l_payment.zipCode') === true ? 'true' : 'false',
                'alipay' => $this->getParameter('c975_l_payment.alipay') === true ? 'true' : 'false',
                'live' => $payment->getLive(),
                'payment' => $payment,
                ));
        }

        //No current payment
        return $this->render('@c975LPayment/pages/noPayment.html.twig');
    }

//PAYMENT FREE AMOUNT
    /**
     * @Route("/payment/request",
     *      name="payment_free_amount")
     * @Method({"GET", "HEAD", "POST"})
     */
    public function freeAmountAction(Request $request)
    {
        //Gets user
        $user = $this->getUser();
        $userId = $user !== null ? $user->getId() : null;

        //Defines form
        $paymentData = array(
            'amount' => null,
            'currency' => $this->getParameter('c975_l_payment.defaultCurrency'),
            'action' => null,
            'description' => null,
            'userId' => $userId,
            'userIp' => $request->getClientIp(),
            'live' => $this->getParameter('c975_l_payment.live'),
            'vat' => $this->getParameter('c975_l_payment.vat'),
            );
        $payment = new Payment($paymentData, $this->getParameter('c975_l_payment.timezone'));
        $form = $this->createForm(PaymentType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Defines payment (Can't re-use $payment object as not set in session + DB, which is done via PaymentService > create method)
            $paymentData = array(
                'amount' => $payment->getAmount() * 100,
                'currency' => $payment->getCurrency(),
                'action' => $payment->getAction(),
                'description' => $payment->getDescription(),
                'userId' => $payment->getUserId(),
                'userIp' => $payment->getUserIp(),
                'live' => $payment->getLive(),
                );
            $paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);
            $paymentService->create($paymentData);

            //Redirects to the payment
            return $this->redirectToRoute('payment_form');
        }

        return $this->render('@c975LPayment/forms/paymentFreeAmount.html.twig', array(
            'form' => $form->createView(),
            'payment' => $payment,
        ));
    }

//PAYMENT DEFINED AMOUNT
    /**
     * @Route("/payment/request/{text}/{amount}/{currency}",
     *      name="payment_request",
     *      requirements={
     *          "amount": "^[0-9]+$",
     *          "currency": "^[a-zA-Z]{3}$"
     *      })
     * @Method({"GET", "HEAD"})
     */
    public function requestAction(Request $request, $text, $amount, $currency)
    {
        //Gets user
        $user = $this->getUser();
        $userId = $user !== null ? $user->getId() : null;

        //Defines payment
        $paymentData = array(
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'action' => null,
            'description' => urldecode($text),
            'userId' => $userId,
            'userIp' => $request->getClientIp(),
            'live' => $this->getParameter('c975_l_payment.live'),
            'vat' => $this->getParameter('c975_l_payment.vat'),
            );
        $paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);
        $paymentService->create($paymentData);

        //Redirects to the payment
        return $this->redirectToRoute('payment_form');
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

        if (!$stripeSession instanceof Payment) {
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
            //Gets paymentService
            $paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);

            //Do the Stripe transaction
            \Stripe\Stripe::setApiKey($paymentService->getSecretKey($stripeSession->getLive()));
            $charge = \Stripe\Charge::create($payment);

            //Gets the manager
            $em = $this->getDoctrine()->getManager();

            //Updates Payment
            $payment = $em->getRepository('c975L\PaymentBundle\Entity\Payment')
                ->findOneByOrderId($stripeSession->getOrderId());
            if ($payment instanceof Payment) {
                $payment
                    ->setStripeFee((int) (($payment->getAmount() * 1.4 / 100) + 25))
                    ->setStripeToken($stripeToken)
                    ->setStripeTokenType($stripeTokenType)
                    ->setStripeEmail($stripeEmail)
                ;

                //Persist in DB
                $em->persist($payment);
                $em->flush();
            }

            //Deletes data in session
            $session->remove('stripe');

            //Gets emailService
            $emailService = $this->get(\c975L\EmailBundle\Service\EmailService::class);

            //Creates email for user
            $subject = $this->getParameter('c975_l_payment.site');
            $subject .= ' - ' . $translator->trans('label.payment_done', array(), 'payment');
            $subject .= ' (' . $payment->getAmount() / 100 . ' ' . $payment->getCurrency() . ')';
            $body = $this->renderView('@c975LPayment/emails/paymentDone.html.twig', array(
                'payment' => $payment,
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
                'payment' => $payment,
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
            $flash .= ' (' . $payment->getAmount() / 100 . ' ' . $payment->getCurrency() . ')';
            $session->getFlashBag()->add('success', $flash);

            //Redirects to returnRoute
            if ($stripeSession->getReturnRoute() !== null) {
                return $this->redirectToRoute($stripeSession->getReturnRoute(), array('orderId' => $payment->getOrderId()));
            //Redirects to payment
            } else {
                return $this->redirectToRoute('payment_display', array('orderId' => $payment->getOrderId()));
            }

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
            'errCode' => $errCode,
            'errMessage' => $errMessage,
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