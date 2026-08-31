<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\ShippingZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingZone>
 */
class ShippingZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingZone::class);
    }

    /**
     * The zones in use, their tiers loaded with them - a handful of rows a shop wrote by hand, read whole rather
     * than queried tier by tier for a basket that changes on every click.
     *
     * @return list<ShippingZone>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('z')
            ->addSelect('r')
            ->leftJoin('z.rates', 'r')
            ->andWhere('z.active = true')
            ->orderBy('z.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
