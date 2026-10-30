<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\StorePurchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StorePurchase>
 */
class StorePurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorePurchase::class);
    }

    public function findOneByTransaction(string $store, string $transactionId): ?StorePurchase
    {
        return $this->findOneBy(['store' => $store, 'transactionId' => $transactionId]);
    }
}
