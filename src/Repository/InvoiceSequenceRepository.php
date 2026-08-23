<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Repository;

use c975L\PaymentBundle\Entity\InvoiceSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSequence>
 */
class InvoiceSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSequence::class);
    }

    /**
     * The next number of that year, drawn once and never twice.
     *
     * The counter is bumped by the database rather than read, incremented in PHP and written back: two orders
     * settled in the same second would both read the same value and be handed the same number, and an invoice
     * sequence that repeats a number is the one thing it must not do. The row the UPDATE touched stays locked
     * until this transaction commits, so the read below sees this request's own value and nobody else's.
     *
     * Deliberately not the unit of work: nothing here loads or flushes an entity, so drawing a number never
     * writes out whatever else the caller had pending on the order it is drawing it for.
     */
    public function next(int $year): int
    {
        $manager = $this->getEntityManager();

        return $manager->wrapInTransaction(function () use ($manager, $year): int {
            if (0 === $this->bump($year)) {
                $this->open($year);
            }

            return (int) $manager
                ->createQuery('SELECT s.lastNumber FROM ' . InvoiceSequence::class . ' s WHERE s.year = :year')
                ->setParameter('year', $year)
                ->getSingleScalarResult();
        });
    }

    // How many rows the increment touched: 1 for a year already open, 0 for the first invoice of a new one
    private function bump(int $year): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('UPDATE ' . InvoiceSequence::class . ' s SET s.lastNumber = s.lastNumber + 1 WHERE s.year = :year')
            ->setParameter('year', $year)
            ->execute();
    }

    /**
     * Opens a year at its first invoice.
     *
     * The unique index settles the race and not this code: two first orders of a January would both find no row,
     * and the one that loses reads the winner's instead of failing an order over it. Written through the
     * connection rather than persisted, so opening a year flushes nothing else.
     */
    private function open(int $year): void
    {
        try {
            $this->getEntityManager()->getConnection()->insert('payment_invoice_sequence', [
                'sequence_year' => $year,
                'last_number' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            if (0 === $this->bump($year)) {
                throw new \RuntimeException(sprintf('The invoice sequence of %d could neither be opened nor read.', $year));
            }
        }
    }
}
