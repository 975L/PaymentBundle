<?php
/*
 * (c) 2017: 975l <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use c975L\PaymentBundle\Entity\Payment;

class PaymentService
{
    private $em;
    private $request;
    private $container;

    public function __construct(
        \Doctrine\ORM\EntityManagerInterface $em,
        \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        \Symfony\Component\DependencyInjection\ContainerInterface $container
    )
    {
        $this->em = $em;
        $this->request = $requestStack->getCurrentRequest();
        $this->container = $container;
    }

    //Creates the payment
    public function create($data)
    {
        $payment = new Payment($data, $this->container->getParameter('c975_l_payment.timezone'));

        //Persists data in DB
        $this->em->persist($payment);
        $this->em->flush();

        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $payment);
    }

    //Re-use a Stripe payment not executed
    public function reUse($payment)
    {
        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $payment);
    }

    //Get publishable key
    public function getPublishableKey($live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_publishable_key_test')) {
                throw new InvalidArgumentException();
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_test');
            $test = true;
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_publishable_key_live')) {
                throw new InvalidArgumentException();
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_live');
            $test = false;
        }

        return array($stripePublishableKey, $test);
    }

    //Get secret key
    public function getSecretKey($live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_secret_key_test')) {
                throw new InvalidArgumentException();
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_test');
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_secret_key_live')) {
                throw new InvalidArgumentException();
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_live');
        }

        return $stripeSecretKey;
    }
}