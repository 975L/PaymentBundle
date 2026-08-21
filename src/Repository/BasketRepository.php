<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\Basket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Basket>
 */
class BasketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Basket::class);
    }

    /**
     * Moves one basket from "validated" to "paid", and says whether this call is the one that moved it.
     *
     * The provider's webhook and the customer's own return confirm the same payment within the same second: both
     * read a "validated" basket and both would deliver it. Written as a single conditional statement so the
     * database, not the two racing workers, decides which one delivers - reading the status and writing it back
     * from PHP leaves exactly the window this closes.
     */
    public function claimPaid(Basket $basket): bool
    {
        $updated = $this->getEntityManager()
            ->createQuery('UPDATE ' . Basket::class . ' b SET b.status = :paid, b.modification = :now WHERE b.id = :id AND b.status = :validated')
            ->setParameter('paid', 'paid')
            ->setParameter('now', new \DateTime())
            ->setParameter('id', $basket->getId())
            ->setParameter('validated', 'validated')
            ->execute();

        return 1 === $updated;
    }

    public function findUnvalidated(int $days)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.creation < :date')
            ->setParameter('status', 'new')
            ->setParameter('date', new \DateTime('-' . $days . ' days'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders of that user the customer area lists - paid or shipped only, newest first.
     *
     * Written out rather than left to a magic findBy*: a basket still "new" or "validated" is a checkout
     * that never completed, and showing it as an order would have the buyer chase a purchase they never made.
     *
     * @return Basket[]
     */
    public function findPaidByUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['paid', 'shipped'])
            ->orderBy('b.creation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
