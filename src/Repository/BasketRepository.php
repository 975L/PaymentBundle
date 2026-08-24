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

    /**
     * The paid orders with something still to post, oldest first.
     *
     * Both flags, because a shop and a campaign both ship: a product order and a crowdfunding counterpart go in
     * the same postbag and are picked from the same list. Each flag is paired with its own "shipped" date and
     * never with the other's: an order of products alone carries no counterpart to post, so its counterpart date
     * stays null for ever and reading the two together would put that order back on every sheet, for ever.
     *
     * @return list<Basket>
     */
    public function findAwaitingShipping(int $limit = 200): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :paid')
            ->andWhere('(BIT_AND(b.contentflags, :physical) > 0 AND b.itemsShipped IS NULL)
                OR (BIT_AND(b.contentflags, :counterparts) > 0 AND b.counterpartsShipped IS NULL)')
            ->setParameter('paid', 'paid')
            ->setParameter('physical', Basket::CONTENT_FLAG_PHYSICAL)
            ->setParameter('counterparts', Basket::CONTENT_FLAG_CF_SHIPPING)
            ->orderBy('b.creation', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The baskets the nightly purge takes away.
     *
     * Read on the last change and not on the creation: a basket is kept alive by the visitor coming back to it,
     * and one filled again yesterday is a shopper still shopping, whatever day it was opened.
     */
    public function findUnvalidated(int $days)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.modification < :date')
            ->setParameter('status', 'new')
            ->setParameter('date', new \DateTime('-' . $days . ' days'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The basket a returning visitor's recovery cookie names, or nothing.
     *
     * Only one still open: an order - validated, paid, shipped - is no longer a basket, and handing one back as
     * the current basket would have the customer go on adding to something already being charged for.
     */
    public function findRecoverable(string $token): ?Basket
    {
        return $this->findOneBy(['recoveryToken' => $token, 'status' => 'new']);
    }

    /**
     * The basket that user left open, newest first, for when their session names none.
     *
     * A customer who logs in on their phone after filling a basket on their laptop finds it there: the row
     * carries their id, which outlives any session or cookie of theirs.
     */
    public function findLastOpenByUser(UserInterface $user): ?Basket
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'new')
            ->orderBy('b.modification', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * The orders placed under that address, paid or shipped only, newest first.
     *
     * By address and not by account, unlike findPaidByUser() above: a shop takes orders from visitors who never
     * opened one, and the address is the only thing those orders and the person who left a review have in common
     * (see ShopBundle's ProductReviewVerifier). Compared in lower case on both sides, an address typed with a
     * capital at the checkout and without one on a review form being the same address.
     *
     * @return Basket[]
     */
    public function findPaidByEmail(string $email): array
    {
        $email = strtolower(trim($email));

        if ('' === $email) {
            return [];
        }

        return $this->createQueryBuilder('b')
            ->andWhere('LOWER(b.email) = :email')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('email', $email)
            ->setParameter('statuses', ['paid', 'shipped'])
            ->orderBy('b.creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Hands the orders left under that address to the account that just proved it owns it, and says how many.
     *
     * A shop takes orders from visitors who never opened an account: those baskets carry the address typed at
     * the checkout and no account at all (see BasketService::create(), which only ever poses the buyer of a
     * visitor already signed in). Nothing else ever links them, so the day their buyer signs in - through a
     * provider or through the form - the orders they placed before become theirs and show up in their customer
     * area, without anyone having to click anything.
     *
     * Only ever called for an account whose address has been proved (see BasketAccountLinkSubscriber): matching
     * on an unproved address would hand a stranger's orders, delivery address included, to whoever registered
     * with their email. Paid and shipped orders only, as findPaidByUser(): an abandoned basket is not a purchase,
     * and re-seating one would collide with the basket the visitor is filling right now.
     *
     * Written as one statement rather than as a loop of loaded entities: this runs on a login, where a customer
     * of ten years is ten rows nobody asked for.
     */
    public function attachOrphansTo(UserInterface $user, string $email): int
    {
        $email = strtolower(trim($email));

        if ('' === $email) {
            return 0;
        }

        return (int) $this->createQueryBuilder('b')
            ->update()
            ->set('b.user', ':user')
            ->andWhere('b.user IS NULL')
            ->andWhere('LOWER(b.email) = :email')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->setParameter('statuses', ['paid', 'shipped'])
            ->getQuery()
            ->execute();
    }

    /**
     * Whether that buyer has already paid for one given item, in whatever order and however long ago.
     *
     * The question a paywall asks before showing what it protects, and this bundle answers it from the orders
     * alone: a satellite gating its own media never has to keep a right of its own beside them. Not to be
     * confused with the downloads of the customer area, which they resemble - a bought file is handed over for
     * as long as its emailed link lives, while this says the purchase happened and says nothing about a delay.
     *
     * A UserInterface is matched on the account and a string on the address, as findPaidByUser() and
     * findPaidByEmail() do, and the match itself is left to the entity: the column is JSON, and a search
     * written in SQL would be one database's and not the next one's.
     *
     * A page gating several assets asks once per asset: keep the answer for the request rather than calling
     * this in a loop over a gallery.
     */
    public function hasPaidFor(UserInterface | string $buyer, string $kind, int | string $itemId): bool
    {
        $baskets = $buyer instanceof UserInterface ? $this->findPaidByUser($buyer) : $this->findPaidByEmail($buyer);

        return array_any($baskets, static fn (Basket $basket): bool => $basket->holdsItem($kind, $itemId));
    }

    /**
     * The baskets a visitor filled in and validated but never paid, untouched long enough to be given up on.
     *
     * Read on the last change like the unvalidated ones, and not on the creation: a customer still coming back
     * to their order is not somebody who abandoned it, whatever day they first opened it.
     *
     * @return Basket[]
     */
    public function findAbandoned(int $days): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.modification < :date')
            ->setParameter('status', 'validated')
            ->setParameter('date', new \DateTime('-' . $days . ' days'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The abandoned baskets due for their next reminder, i.e. the ones that have had exactly that many already.
     *
     * The opposition is read here and not left to the caller: a customer who has asked to hear no more about
     * their order is one this query must never hand back, whichever reminder is being sent.
     *
     * Payment links are left out for the same reason: nobody walked out of a checkout there, the order having
     * been written in the back-office by the shopkeeper - who is chasing their own client themselves, and in
     * their own words rather than in a reminder that tells them they validated something.
     *
     * @return Basket[]
     */
    public function findToRemind(int $days, int $alreadySent): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.reminderOptOutAt IS NULL')
            ->andWhere('b.contentflags != :service')
            ->andWhere('b.email IS NOT NULL')
            ->andWhere('b.remindersSent = :sent')
            ->andWhere('b.modification < :date')
            ->setParameter('status', 'validated')
            ->setParameter('service', Basket::CONTENT_FLAG_SERVICE)
            ->setParameter('sent', $alreadySent)
            ->setParameter('date', new \DateTime('-' . $days . ' days'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders that have stopped being current business and belong in the intermediate archive.
     *
     * Read on the creation rather than on the shipping date: an order delivered within days of being placed
     * makes the two dates the same to within a rounding error, and the creation is the one every order carries.
     *
     * @return Basket[]
     */
    public function findToArchive(int $years): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.archived IS NULL')
            ->andWhere('b.creation < :date')
            ->setParameter('statuses', ['paid', 'shipped'])
            ->setParameter('date', new \DateTime('-' . $years . ' years'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders whose accounting obligation has run out and which nothing keeps any more.
     *
     * The ten years run from the close of the accounting year and not from the order itself, so the cut is the
     * first of January of that year: reading it off the order date would take an order away several months early.
     *
     * @return Basket[]
     */
    public function findExpired(int $years): array
    {
        $year = (int) new \DateTime()->format('Y') - $years;

        return $this->createQueryBuilder('b')
            ->andWhere('b.creation < :date')
            ->setParameter('date', new \DateTime($year . '-01-01'))
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders whose payment the provider never confirmed, and which were delivered all the same.
     *
     * Nothing else says it: the order looks settled from every screen, its payment row says it never was, and the
     * two are only ever read apart. An order with nothing to pay is left out - it is delivered without a payment
     * row at all, which is the one case BasketService::paid() lets through on its own.
     *
     * Orders placed in test mode are left out here as everywhere in this check: a shop trying its checkout out
     * writes rows nobody ever settles, and reporting them would bury the one order that is genuinely wrong.
     *
     * @return list<Basket>
     */
    public function findDeliveredWithoutFinishedPayment(\DateTimeInterface $since, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.payment', 'p')
            ->andWhere('b.status IN (:delivered)')
            ->andWhere('b.creation >= :since')
            ->andWhere('b.total + b.shipping - b.discountAmount > 0')
            ->andWhere('p.id IS NULL OR p.isFinished = false')
            ->andWhere('b.testMode = false')
            ->setParameter('delivered', ['paid', 'shipped'])
            ->setParameter('since', $since)
            ->orderBy('b.creation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders the shop was paid a different amount for than the one they add up to.
     *
     * The comparison is made twice on purpose: the database narrows it down, and the caller settles it on
     * Basket::getPayable() itself - the floor that method applies to a negative payable has no equivalent in DQL,
     * and what a payment row must match is what the checkout charged, not an expression recopied here.
     *
     * Test orders are left out: they are charged against the provider's sandbox and nothing keeps their two rows
     * in step once the trial is over.
     *
     * @return list<Basket>
     */
    public function findWithPaymentAmountMismatch(\DateTimeInterface $since, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.payment', 'p')
            ->andWhere('p.isFinished = true')
            ->andWhere('b.creation >= :since')
            ->andWhere('p.amount <> b.total + b.shipping - b.discountAmount OR LOWER(p.currency) <> LOWER(b.currency)')
            ->andWhere('b.testMode = false')
            ->setParameter('since', $since)
            ->orderBy('b.creation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The delivered orders carrying no invoice number, which the accounting sequence has no way of showing.
     *
     * The number is minted when the order is validated, so one missing on a delivered order means it was written
     * around the checkout - by a fixture, an import or a hand-made row - and no invoice can ever be drawn for it.
     *
     * Test orders are left out: no invoice is ever drawn for them.
     *
     * @return list<Basket>
     */
    public function findDeliveredWithoutNumber(\DateTimeInterface $since, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status IN (:delivered)')
            ->andWhere('b.creation >= :since')
            ->andWhere('b.number IS NULL')
            ->andWhere('b.testMode = false')
            ->setParameter('delivered', ['paid', 'shipped'])
            ->setParameter('since', $since)
            ->orderBy('b.creation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The orders placed since that date, newest first, for whoever has to read their lines again.
     *
     * Test orders excluded, this being read by the integrity check alone: an order placed to try the checkout out
     * is not one whose lines have to add up.
     *
     * @return list<Basket>
     */
    public function findOrdersSince(\DateTimeInterface $since, int $limit = 500): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status IN (:delivered)')
            ->andWhere('b.creation >= :since')
            ->andWhere('b.testMode = false')
            ->setParameter('delivered', ['paid', 'shipped'])
            ->setParameter('since', $since)
            ->orderBy('b.creation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The baskets and the orders still awaiting payment, newest first - everything a customer can still be charged for.
     *
     * Test baskets excluded, this being read by the integrity check alone: nobody is ever charged for one.
     *
     * @return list<Basket>
     */
    public function findPayable(int $limit = 200): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status IN (:open)')
            ->andWhere('b.archived IS NULL')
            ->andWhere('b.testMode = false')
            ->setParameter('open', ['new', 'validated'])
            ->orderBy('b.modification', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
