<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\PaymentServiceInterface;
use c975L\PaymentBundle\Service\Email\PaymentEmailInterface;
use c975L\PaymentBundle\Service\Stripe\PaymentStripeInterface;
use c975L\PaymentBundle\Service\Tools\PaymentToolsInterface;

/**
 * Main Services related to Payment
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentService implements PaymentServiceInterface
{
    private $container;
    private $em;
    private $request;
    private $paymentEmail;
    private $paymentStripe;
    private $paymentTools;

    public function __construct(
        ContainerInterface $container,
        EntityManagerInterface $em,
        RequestStack $requestStack,
        PaymentEmailInterface $paymentEmail,
        PaymentStripeInterface $paymentStripe,
        PaymentToolsInterface $paymentTools
    )
    {
        $this->container = $container;
        $this->em = $em;
        $this->request = $requestStack->getCurrentRequest();
        $this->paymentEmail = $paymentEmail;
        $this->paymentStripe = $paymentStripe;
        $this->paymentTools = $paymentTools;
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
            $this->paymentTools->createFlash('payment_done', array('%amount%' => $amount));

            //Deletes data in session
            $this->request->getSession()->remove('stripe');

            return $payment->getOrderId();
        //An error has occured
        } else {
            //Sends an email
            $this->paymentEmail->sendError($paymentDone);

            //Creates flash
            $this->paymentTools->createFlashError($paymentDone);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $paymentData)
    {
        //Payment or product under test
        $live = isset($paymentData['live']) ? $paymentData['live'] : $this->container->getParameter('c975_l_payment.live');
        if (false === $this->container->getParameter('c975_l_payment.live') || false === $live) {
            $paymentData['description'] = '(TEST) ' . $paymentData['description'];
            $paymentData['live'] = false;
        //Payment live
        } else {
            $paymentData['live'] = true;
        }

        //Registers the Payment
        $payment = new Payment($paymentData, $this->container->getParameter('c975_l_payment.timezone'));
        $this->register($payment);
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
            'live' => $this->container->getParameter('c975_l_payment.live'),
            'vat' => $this->container->getParameter('c975_l_payment.vat'),
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
            'currency' => $this->container->getParameter('c975_l_payment.defaultCurrency'),
            'action' => 'free_amount_payment',
            'description' => null,
            'userId' => null !== $user ? $user->getId() : null,
            'userIp' => $this->request->getClientIp(),
            'live' => $this->container->getParameter('c975_l_payment.live'),
            'vat' => $this->container->getParameter('c975_l_payment.vat'),
            );
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
                'site' => $this->container->getParameter('c975_l_payment.site'),
                'image' => $this->container->getParameter('c975_l_payment.image'),
                'zipCode' => $this->container->getParameter('c975_l_payment.zipCode') === true ? 'true' : 'false',
                'alipay' => $this->container->getParameter('c975_l_payment.alipay') === true ? 'true' : 'false',
                'live' => $payment->getLive(),
                'payment' => $payment,
                );
        }

        return null;
    }
}
