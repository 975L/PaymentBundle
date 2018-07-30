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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Form\PaymentType;
use c975L\PaymentBundle\Service\PaymentService;

class PaymentController extends Controller
{
    private $accessGranted;
    private $roleNeeded;

    public function __construct(AuthorizationCheckerInterface $authChecker, string $roleNeeded)
    {
        $this->accessGranted = $authChecker->isGranted($roleNeeded);
    }

//DASHBOARD
    /**
     * @Route("/payment/dashboard",
     *      name="payment_dashboard")
     * @Method({"GET", "HEAD"})
     */
    public function dashboard(Request $request)
    {
        //Access denied
        if (true !== $this->accessGranted) {
            throw $this->createAccessDeniedException();
        }

        //Gets payments
        $payments = $this->getDoctrine()
            ->getManager()
            ->getRepository('c975LPaymentBundle:Payment')
            ->findAll(array(), array('id' => 'DESC'));

        //Pagination
        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $payments,
            $request->query->getInt('p', 1),
            50
        );

        //Renders the dashboard
        return $this->render('@c975LPayment/pages/dashboard.html.twig', array(
            'payments' => $pagination,
        ));
    }

//DISPLAY
    /**
     * @Route("/payment/{orderId}",
     *      name="payment_display",
     *      requirements={"orderId": "^[0-9\-]+$"})
     * @Method({"GET", "HEAD"})
     */
    public function display(Request $request, Payment $payment)
    {
        //Renders the payment
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
    public function form(Request $request, PaymentService $paymentService)
    {
        //Grabs data from session
        $payment = $request->getSession()->get('stripe');

        //Renders the payment form
        if ($payment instanceof Payment) {
            return $this->render('@c975LPayment/pages/payment.html.twig', array(
                'key' => $paymentService->getPublishableKey($payment->getLive()),
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
    public function freeAmount(Request $request, PaymentService $paymentService)
    {
        //Gets userId
        $userId = null !== $this->getUser() ? $this->getUser()->getId() : null;

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
    public function request(Request $request, PaymentService $paymentService, $text, $amount, $currency)
    {
        //Gets userId
        $userId = null !== $this->getUser() ? $this->getUser()->getId() : null;

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
    public function charge(Request $request, PaymentService $paymentService)
    {
        //Grabs data from session
        $stripeSession = $request->getSession()->get('stripe');

        if (!$stripeSession instanceof Payment) {
            throw $this->createNotFoundException();
        }

        //Creates the charge on Stripe's servers - This will charge user's card
        $orderId = $paymentService->charge($stripeSession);

        if (false !== $orderId) {
            //Redirects to returnRoute, if defined, with orderId
            if (null !== $stripeSession->getReturnRoute()) {
                return $this->redirectToRoute($stripeSession->getReturnRoute(), array('orderId' => $orderId));
            }

            //Redirects to payment
            return $this->redirectToRoute('payment_display', array('orderId' => $orderId));
        }

        //Redirects to payment
        return $this->redirectToRoute('payment_display', array('orderId' => $stripeSession->getOrderId()));
    }
}
