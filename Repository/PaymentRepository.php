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

/**
 * Repository for Payment Entity
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentRepository extends EntityRepository
{
    /**
     * Finds Payment not finished with its orderId
     * @return Payment|null
     */
    public function findOneByOrderIdNotFinished($orderId)
    {
        $qb = $this->createQueryBuilder('p');
        $qb->select('p')
            ->where('p.orderId = :orderId')
            ->andWhere('p.finished is NULL')
            ->setParameter('orderId', strtoupper($orderId))
            ;

        return $qb->getQuery()->getOneOrNullResult();
    }
}