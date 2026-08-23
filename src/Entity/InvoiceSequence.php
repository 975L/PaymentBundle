<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The counter an invoice number is drawn from, one row per year.
 *
 * A table of its own and not MAX(invoice_number) + 1: an invoice sequence has to be continuous and without gaps,
 * and two orders settled in the same second would read the same maximum and be handed the same number. This row
 * is locked while it is read (see InvoiceSequenceRepository::next()), which is the whole reason it exists.
 *
 * One row per year because the year is in the number: the sequence restarts at 1 each January, which is what an
 * accountant expects and what the number itself already says.
 */
#[ORM\Entity(repositoryClass: InvoiceSequenceRepository::class)]
#[ORM\Table(name: 'payment_invoice_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_sequence_year', columns: ['sequence_year'])]
class InvoiceSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // "sequence_year" and not "year", which several engines read as a type keyword
    #[ORM\Column(name: 'sequence_year', type: 'smallint')]
    private int $year = 0;

    // The last number handed out, so the next invoice of that year is this plus one
    #[ORM\Column]
    private int $lastNumber = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }

    public function setLastNumber(int $lastNumber): static
    {
        $this->lastNumber = $lastNumber;

        return $this;
    }
}
