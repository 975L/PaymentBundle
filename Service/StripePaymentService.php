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

    public function __construct(
        \Doctrine\ORM\EntityManagerInterface $em,
        \Symfony\Component\HttpFoundation\RequestStack $requestStack
    )
    {
        $this->em = $em;
        $this->request = $requestStack->getCurrentRequest();
    }

    //Creates the stripe payment
    public function create($data)
    {
        $stripePayment = new StripePayment($data);

        //Persists data in DB
        $this->em->persist($stripePayment);
        $this->em->flush();

        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $stripePayment);
    }
}