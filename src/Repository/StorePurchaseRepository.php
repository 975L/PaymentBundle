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

    // Counts a refunded purchase again, the refund having been reversed, in one conditional statement: a request that read the row refunded before another delivered it writes nothing over that delivery. The entity is then read back from the row
    public function restore(StorePurchase $purchase): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE ' . StorePurchase::class . ' p SET p.isRefunded = false, p.refunded = NULL, p.delivered = NULL WHERE p.id = :id AND p.isRefunded = true')
            ->setParameter('id', $purchase->getId())
            ->execute();

        $this->getEntityManager()->refresh($purchase);
    }

    // Dates the delivery of a purchase not delivered yet, and says whether this call is the one that did: of two requests verifying the same proof at once, only one delivers (see InAppPurchaseService)
    public function claimDelivery(StorePurchase $purchase): bool
    {
        $updated = $this->getEntityManager()
            ->createQuery('UPDATE ' . StorePurchase::class . ' p SET p.delivered = :now WHERE p.id = :id AND p.delivered IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $purchase->getId())
            ->execute();

        return 1 === $updated;
    }
}
