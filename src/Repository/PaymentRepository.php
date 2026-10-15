<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    // The payments a provider confirmed whose order was never delivered, the last hour left out while the webhook may still be settling it - a refunded order counts as delivered, a test order is left out, a payment with no order is kept
    /** @return list<Payment> */
    public function findFinishedWithoutDeliveredBasket(\DateTimeInterface $since, \DateTimeInterface $before, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.basket', 'b')
            ->andWhere('p.isFinished = true')
            ->andWhere('p.modification >= :since')
            ->andWhere('p.modification <= :before')
            ->andWhere('b.id IS NULL OR b.status NOT IN (:delivered)')
            ->andWhere('b.id IS NULL OR b.testMode = false')
            ->setParameter('since', $since)
            ->setParameter('before', $before)
            ->setParameter('delivered', BasketStatus::RECORDED)
            ->orderBy('p.modification', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
