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

// The counter an invoice number is drawn from, one row per year and per kind: locked while read so two orders settled in the same second never share a number (see InvoiceSequenceRepository::next()), restarting each January as the number says, credit notes kept in a series of their own so the invoices' one holds no gap
#[ORM\Entity(repositoryClass: InvoiceSequenceRepository::class)]
#[ORM\Table(name: 'payment_invoice_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_sequence_kind_year', columns: ['kind', 'sequence_year'])]
class InvoiceSequence
{
    public const string KIND_INVOICE = 'invoice';
    public const string KIND_CREDIT_NOTE = 'credit_note';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Which series the counter numbers, one of the KIND_* above. The default carries the rows written before credit notes had a series of their own
    #[ORM\Column(length: 20, options: ['default' => self::KIND_INVOICE])]
    private string $kind = self::KIND_INVOICE;

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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
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
