<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\Discount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Discount>
 */
class DiscountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discount::class);
    }

    // The code as the customer typed it, whatever case they used - the column holds it normalised (see Discount::setCode())
    public function findOneByCode(string $code): ?Discount
    {
        return $this->findOneBy(['code' => mb_strtoupper(trim($code))]);
    }

    /**
     * Counts one more order against the quota, and says whether there was still room for it.
     *
     * A single statement rather than a read then a write: two orders settling at the same second both read the same count, and both would fit under a quota that only had room for one. The condition is the quota itself, so the database is what refuses the second.
     *
     * It carries the switch and the dates too, and not the quota alone: a code turned off or run out of validity between the moment the basket was priced and the moment it is claimed must not be counted as used (see BasketCodeService::claim()).
     */
    public function claimUse(Discount $discount): bool
    {
        return 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE payment_discount SET used_count = used_count + 1'
                . ' WHERE id = :id AND active = 1'
                . ' AND (valid_from IS NULL OR valid_from <= :now)'
                . ' AND (valid_until IS NULL OR valid_until >= :now)'
                . ' AND (max_uses = 0 OR used_count < max_uses)',
            ['id' => $discount->getId(), 'now' => date('Y-m-d H:i:s')]
        );
    }

    // Gives one use back, the reverse of claimUse(), to an order that stopped being one before it was paid
    public function releaseUse(Discount $discount): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE payment_discount SET used_count = used_count - 1 WHERE id = :id AND used_count > 0',
            ['id' => $discount->getId()]
        );

        // Read back: the statement went round the unit of work, and the same request goes on to price a basket against this quota (see BasketCodeService::release()). A closed manager has no such request to serve, and refreshing would throw over the cause that closed it
        if ($this->getEntityManager()->isOpen()) {
            $this->getEntityManager()->refresh($discount);
        }
    }
}
