<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\PaymentServiceInterface;
use Knp\Component\Pager\PaginatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Main controller class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2017 975L <contact@975l.com>
 */
class PaymentController extends Controller
{
    /**
     * Stores PaymentService
     * @var PaymentServiceInterface
     */
    private $paymentService;

    public function __construct(PaymentServiceInterface $paymentService)
    {
        $this->paymentService = $paymentService;
    }

//DASHBOARD

    /**
     * Displays the dashboard
     * @return Response
     * @throws AccessDeniedException
     *
     * @Route("/payment/dashboard",
     *      name="payment_dashboard")
     * @Method({"GET", "HEAD"})
     */
    public function dashboard(Request $request, PaginatorInterface $paginator)
    {
        $this->denyAccessUnlessGranted('c975LPayment-dashboard', null);

        //Pagination
        $payments = $paginator->paginate(
            $this->paymentService->getAll(),
            $request->query->getInt('p', 1),
            50
        );

        //Renders the dashboard
        return $this->render('@c975LPayment/pages/dashboard.html.twig', array(
            'payments' => $payments,
        ));
    }

//DISPLAY

    /**
     * Displays Payment using its orderId
     * @return Response
     *
     * @Route("/payment/{orderId}",
     *      name="payment_display",
     *      requirements={"orderId": "^[0-9\-]+$"})
     * @Method({"GET", "HEAD"})
     */
    public function display(Request $request, Payment $payment, ConfigServiceInterface $configService)
    {
        return $this->render('@c975LPayment/pages/display.html.twig', array(
            'payment' => $payment,
            'siteName' => $configService->getParameter('c975LCommon.site'),
        ));
    }

//CONFIG

    /**
     * Displays the configuration
     * @return Response
     * @throws AccessDeniedException
     *
     * @Route("/payment/config",
     *      name="payment_config")
     * @Method({"GET", "HEAD", "POST"})
     */
    public function config(Request $request, ConfigServiceInterface $configService)
    {
        $this->denyAccessUnlessGranted('c975LPayment-config', null);

        //Defines form
        $form = $configService->createForm('c975l/payment-bundle');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Validates config
            $configService->setConfig($form);

            //Redirects
            return $this->redirectToRoute('payment_dashboard');
        }

        //Renders the config form
        return $this->render('@c975LConfig/forms/config.html.twig', array(
            'form' => $form->createView(),
            'toolbar' => '@c975LPayment',
        ));
    }

//FORM

    /**
     * Displays Stripe form to proceed to payment
     * @return Response
     *
     * @Route("/payment",
     *      name="payment_form")
     * @Method({"GET", "HEAD"})
     */
    public function form(Request $request)
    {
        //Renders form payment
        $paymentData = $this->paymentService->setDataFromSession('stripe');
        if (is_array($paymentData)) {
            return $this->render('@c975LPayment/pages/payment.html.twig', $paymentData);
        }

        //No current payment
        return $this->render('@c975LPayment/pages/noPayment.html.twig');
    }

//PAYMENT FREE AMOUNT

    /**
     * Displays the form to proceed to a free amount payment
     * @return Response|Redirect
     *
     * @Route("/payment/request",
     *      name="payment_free_amount")
     * @Method({"GET", "HEAD", "POST"})
     */
    public function freeAmount(Request $request, ConfigServiceInterface $configService)
    {
        //Defines form
        $paymentData = $this->paymentService->defineFreeAmount($this->getUser());
        $payment = new Payment($paymentData, $configService->getParameter('c975LPayment.timezone'));
        $form = $this->paymentService->createForm('free_amount', $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Creates the Payment
            $this->paymentService->createFreeAmount($payment);

            //Redirects to the payment
            return $this->redirectToRoute('payment_form');
        }

        //Renders form for payment
        return $this->render('@c975LPayment/forms/paymentFreeAmount.html.twig', array(
            'form' => $form->createView(),
            'payment' => $payment,
        ));
    }

//PAYMENT DEFINED AMOUNT

    /**
     * Displays form for defined amount
     * @return Redirect
     *
     * @Route("/payment/request/{text}/{amount}/{currency}",
     *      name="payment_request",
     *      requirements={
     *          "amount": "^[0-9]+$",
     *          "currency": "^[a-zA-Z]{3}$"
     *      })
     * @Method({"GET", "HEAD"})
     */
    public function request(Request $request, $text, $amount, $currency)
    {
        //Creates the Payment
        $this->paymentService->createDefinedAmount($this->getUser(), $text, $amount, $currency);

        //Redirects to the payment
        return $this->redirectToRoute('payment_form');
    }

//CHARGE

    /**
     * Proceeds to charge Payment server
     * @return Redirect
     * @throws NotFoundHttpException
     *
     * @Route("/payment-charge",
     *      name="payment_charge")
     * @Method({"GET", "POST", "HEAD"})
     */
    public function charge(Request $request)
    {
        $payment = $this->paymentService->getFromSession('stripe');
        if ($payment instanceof Payment) {
            //Creates the charge on Stripe's servers - This will charge user's card
            $orderId = $this->paymentService->charge('stripe', $payment);

            if (false !== $orderId) {
                //Redirects to returnRoute, if defined, with orderId
                if (null !== $payment->getReturnRoute()) {
                    return $this->redirectToRoute($payment->getReturnRoute(), array('orderId' => $orderId));
                }

                //Redirects to payment
                return $this->redirectToRoute('payment_display', array('orderId' => $orderId));
            }

            //Redirects to payment
            return $this->redirectToRoute('payment_display', array('orderId' => $payment->getOrderId()));
        }

        //Not found
        throw $this->createNotFoundException();
    }
}
