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

    /**
     * The payments a provider confirmed and whose order was never delivered.
     *
     * The one thing no screen of this bundle shows: the customer was charged, the order stayed where it was, and
     * the two rows are only ever read apart. Payments confirmed within the last hour are left out - the webhook
     * and the customer's own return settle the order within seconds of the charge, and a run landing in between
     * would report one that is about to be delivered.
     *
     * A payment settling a test order is left out, as everywhere in this check; one carrying no order at all is
     * kept, there being nothing to read a test flag off.
     *
     * @return list<Payment>
     */
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
            ->setParameter('delivered', ['paid', 'shipped'])
            ->orderBy('p.modification', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
