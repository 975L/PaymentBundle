<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Service\Email\PaymentEmailInterface;
use c975L\PaymentBundle\Service\Stripe\PaymentStripeInterface;
use c975L\ServicesBundle\Service\ServiceToolsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PaymentService class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2017 975L <contact@975l.com>
 */
class PaymentService implements PaymentServiceInterface
{
    /**
     * Stores ConfigServiceInterface
     * @var ConfigServiceInterface
     */
    private $configService;

    /**
     * Stores EntityManagerInterface
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * Stores current Request
     * @var Request
     */
    private $request;

    /**
     * Stores PaymentEmailInterface
     * @var PaymentEmailInterface
     */
    private $paymentEmail;

    /**
     * Stores PaymentFormFactoryInterface
     * @var PaymentFormFactoryInterface
     */
    private $paymentFormFactory;

    /**
     * Stores PaymentStripeInterface
     * @var PaymentStripeInterface
     */
    private $paymentStripe;

    /**
     * Stores ServiceToolsInterface
     * @var ServiceToolsInterface
     */
    private $serviceTools;

    public function __construct(
        ConfigServiceInterface $configService,
        EntityManagerInterface $em,
        RequestStack $requestStack,
        PaymentEmailInterface $paymentEmail,
        PaymentFormFactoryInterface $paymentFormFactory,
        PaymentStripeInterface $paymentStripe,
        ServiceToolsInterface $serviceTools
    )
    {
        $this->configService = $configService;
        $this->em = $em;
        $this->request = $requestStack->getCurrentRequest();
        $this->paymentEmail = $paymentEmail;
        $this->paymentFormFactory = $paymentFormFactory;
        $this->paymentStripe = $paymentStripe;
        $this->serviceTools = $serviceTools;
    }

    /**
     * {@inheritdoc}
     */
    public function charge(string $service, Payment $paymentSession)
    {
        //Loads Payment from database otherwise when persisting a new one will be created as coming from session and not the same object
        $payment = $this->em
            ->getRepository('c975LPaymentBundle:Payment')
            ->findOneByOrderIdNotFinished($paymentSession->getOrderId())
        ;

        //Adds not mapped data
        $payment
            ->setLive($paymentSession->getLive())
            ->setReturnRoute($paymentSession->getReturnRoute())
        ;

        $paymentDone = false;

        //Stripe payment
        if ('stripe' === $service) {
            $paymentDone = $this->paymentStripe->charge($payment);
        }

        //Payment done
        if ($paymentDone) {
            //Persist in DB
            $this->em->persist($payment);
            $this->em->flush();

            //Sends emails (user + site)
            $amount = $payment->getAmount() / 100 . ' ' . $payment->getCurrency();
            $this->paymentEmail->send($payment, $amount);

            //Creates flash
            $this->serviceTools->createFlash('payment', 'label.payment_done', 'success', array('%amount%' => $amount));

            //Deletes data in session
            $this->request->getSession()->remove('stripe');

            return $payment->getOrderId();
        //An error has occured
        } else {
            $errData = $paymentDone;

            //Sends an email
            $this->paymentEmail->sendError('stripe', $errData);

            //Flash specific error message
            if ($errData['display']) {
                $this->serviceTools->createFlash('payment', 'text.error_payment', 'danger');
                $this->serviceTools->createFlash(null, $errMessage, 'danger');
            //Flash generic error message
            } else {
                $this->serviceTools->createFlash('payment', 'text.error_payment_generic', 'danger');
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $paymentData)
    {
        //Payment or product under test
        $live = $paymentData['live'] ?? $this->configService->getParameter('c975LPayment.live');
        if (false === $this->configService->getParameter('c975LPayment.live') || false === $live) {
            $paymentData['description'] = '(TEST) ' . $paymentData['description'];
            $paymentData['live'] = false;
        //Payment live
        } else {
            $paymentData['live'] = true;
        }

        //Registers the Payment
        $payment = new Payment($paymentData, $this->configService->getParameter('c975LPayment.timezone'));
        $this->register($payment);
    }

    /**
     * {@inheritdoc}
     */
    public function createForm(string $name, Payment $payment)
    {
        return $this->paymentFormFactory->create($name, $payment);
    }

    /**
     * {@inheritdoc}
     */
    public function createFreeAmount(Payment $payment)
    {
        //Registers the Payment
        $payment->setAmount($payment->getAmount() * 100);
        $this->register($payment);
    }

    /**
     * {@inheritdoc}
     */
    public function createDefinedAmount($user, string $text, int $amount, string $currency)
    {
        $paymentData = array(
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'action' => 'defined_amount_payment: ' . $text . ' -> ' . $amount . strtoupper($currency),
            'description' => urldecode($text),
            'userId' => null !== $user ? $user->getId() : null,
            'userIp' => $this->request->getClientIp(),
            'live' => $this->configService->getParameter('c975LPayment.live'),
            'vat' => $this->configService->getParameter('c975LPayment.vat'),
            );
        $this->create($paymentData);
    }

    /**
     * {@inheritdoc}
     */
    public function defineFreeAmount($user)
    {
        return array(
            'amount' => null,
            'currency' => $this->configService->getParameter('c975LPayment.defaultCurrency'),
            'action' => 'free_amount_payment',
            'description' => null,
            'userId' => null !== $user ? $user->getId() : null,
            'userIp' => $this->request->getClientIp(),
            'live' => $this->configService->getParameter('c975LPayment.live'),
            'vat' => $this->configService->getParameter('c975LPayment.vat'),
            );
    }

    /**
     * {@inheritdoc}
     */
    public function error(Payment $payment)
    {
        //Sends email
        $this->paymentEmail->sendError('validation', $payment);

        //Creates flash
        $this->serviceTools->createFlash('payment', 'text.product_no_delivered', 'danger');
    }

    /**
     * {@inheritdoc}
     */
    public function getAll()
    {
        return $this->em
            ->getRepository('c975LPaymentBundle:Payment')
            ->findAll(array(), array('id' => 'DESC'))
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function getFromSession(string $kind)
    {
        return $this->request->getSession()->get($kind);
    }

    /**
     * {@inheritdoc}
     */
    public function getParameter(string $parameter)
    {
        return $this->configService->getParameter($parameter);
    }

    /**
     * {@inheritdoc}
     */
    public function register(Payment $payment)
    {
        //Persists data in DB
        $this->em->persist($payment);
        $this->em->flush();

        //Saves in the session
        $this->request->getSession()->set('stripe', $payment);
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFromSession(string $kind)
    {
        $payment = $this->getFromSession($kind);

        if ($payment instanceof Payment) {
            return array(
                'key' => $this->paymentStripe->getPublishableKey($payment->getLive()),
                'site' => $this->configService->getParameter('c975LCommon.site'),
                'image' => $this->configService->getParameter('c975LPayment.image'),
                'zipCode' => $this->configService->getParameter('c975LPayment.zipCode') ? 'true' : 'false',
                'alipay' => $this->configService->getParameter('c975LPayment.alipay') ? 'true' : 'false',
                'live' => $payment->getLive(),
                'payment' => $payment,
                );
        }

        return null;
    }
}
