<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use Doctrine\ORM\EntityRepository;
use c975L\PaymentBundle\Entity\Payment;

class PaymentRepository extends EntityRepository
{
    //Loads Payment
    public function findOneByOrderIdNotFinished($orderId)
    {
        return $this->createQueryBuilder('p')
            ->where('p.orderId = :orderId')
            ->andWhere('p.finished is NULL')
            ->setParameter('orderId', strtoupper($orderId))
            ->getQuery()
            ->getOneOrNullResult();
    }
}