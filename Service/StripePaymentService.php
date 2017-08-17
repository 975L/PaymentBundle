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
use c975L\PaymentBundle\Entity\StripePayment;

class StripePaymentService
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

    //Creates the Stripe payment
    public function create($data)
    {
        $stripePayment = new StripePayment($data, $this->container->getParameter('c975_l_payment.timezone'));

        //Persists data in DB
        $this->em->persist($stripePayment);
        $this->em->flush();

        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $stripePayment);
    }

    //Re-use a Stripe payment not executed
    public function reUse($stripePayment)
    {
        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $stripePayment);
    }
}