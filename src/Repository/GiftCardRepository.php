<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\GiftCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftCard>
 */
class GiftCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftCard::class);
    }

    public function findOneByCode(string $code): ?GiftCard
    {
        return $this->findOneBy(['code' => mb_strtoupper(trim($code))]);
    }

    // The card an address points at, which is never its code (see GiftCard::$shareToken)
    public function findOneByShareToken(string $shareToken): ?GiftCard
    {
        return $this->findOneBy(['shareToken' => $shareToken]);
    }

    /**
     * Takes an amount off a card, and says whether the card still held it.
     *
     * A single statement, for the same reason as DiscountRepository::claimUse(): two orders settling at the same second both read the same balance, and both would spend it. The remaining balance is the condition, so the database is what refuses the second.
     *
     * The switch and the expiry date are conditions of it as well, and not the balance alone: a card turned off or expired between the moment the basket was priced and the moment it is paid must not be drained.
     */
    public function claimAmount(GiftCard $giftCard, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        return 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE payment_gift_card SET balance = balance - :amount'
                . ' WHERE id = :id AND active = 1'
                . ' AND (valid_until IS NULL OR valid_until >= :now)'
                . ' AND balance >= :amount',
            ['id' => $giftCard->getId(), 'amount' => $amount, 'now' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * The cards one order paid for, which is what its confirmation email prints.
     *
     * @return GiftCard[]
     */
    public function findByBasketNumber(string $number): array
    {
        return $this->findBy(['issuedByBasket' => $number], ['id' => 'ASC']);
    }
}
