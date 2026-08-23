<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\DiscountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// A promotional code, written by hand in the back office and offered to whoever the shop hands it to - collective by nature, where a GiftCard is one bearer's own money
#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table(name: 'payment_discount')]
#[ORM\UniqueConstraint(name: 'uniq_discount_code', columns: ['code'])]
class Discount implements \Stringable
{
    // Taken off as a share of what the basket holds, or as a fixed sum - the only two ways a shop ever writes a promotion
    public const string KIND_PERCENTAGE = 'percentage';
    public const string KIND_AMOUNT = 'amount';

    public const array KINDS = [self::KIND_PERCENTAGE, self::KIND_AMOUNT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Stored as it is typed, in upper case: a customer reading a code off a flyer types it however they like, and DiscountService normalises before looking it up
    #[ORM\Column(length: 40)]
    private ?string $code = null;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_PERCENTAGE;

    // A share out of a hundred, or a sum in the currency's smallest unit - which of the two is read off $kind, one column rather than two nullable ones nobody could tell apart
    #[ORM\Column]
    private int $value = 0;

    // Left empty on a code that is offered in one currency only through the shop's own setting: an amount taken off a basket in another currency would be a rate nobody wrote
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $validFrom = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    // What the basket has to hold before the code applies, in cents - compared against the items alone, never against the shipping
    #[ORM\Column]
    private int $minimumTotal = 0;

    // How many orders may use it, zero meaning as many as come
    #[ORM\Column]
    private int $maxUses = 0;

    // Raised once a payment is settled and never at validation: a basket abandoned at the payment page must not burn one of the quota
    #[ORM\Column]
    private int $usedCount = 0;

    // The switch that takes a code out of circulation without deleting it, and so without losing what it was used on
    #[ORM\Column]
    private bool $active = true;

    // Whether it was born while the shop was charging with the provider's test keys. Read by BasketCodeService, which never lets the two mix: a card issued by a rehearsal is not money, and a real code must not be spent on an order nobody is charged for
    #[ORM\Column]
    private bool $testMode = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    // Normalised on the way in rather than at every lookup: the column is unique, and two rows differing by their case would both be reachable by the same typed code
    public function setCode(?string $code): static
    {
        $this->code = null === $code ? null : mb_strtoupper(trim($code));

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = \in_array($kind, self::KINDS, true) ? $kind : self::KIND_PERCENTAGE;

        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = max(0, $value);

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = null === $currency || '' === trim($currency) ? null : mb_strtoupper(trim($currency));

        return $this;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): static
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getMinimumTotal(): int
    {
        return $this->minimumTotal;
    }

    public function setMinimumTotal(int $minimumTotal): static
    {
        $this->minimumTotal = max(0, $minimumTotal);

        return $this;
    }

    public function getMaxUses(): int
    {
        return $this->maxUses;
    }

    public function setMaxUses(int $maxUses): static
    {
        $this->maxUses = max(0, $maxUses);

        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function setUsedCount(int $usedCount): static
    {
        $this->usedCount = max(0, $usedCount);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreation(): ?\DateTimeInterface
    {
        return $this->creation;
    }

    public function setCreation(?\DateTimeInterface $creation): static
    {
        $this->creation = $creation;

        return $this;
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function setTestMode(bool $testMode): static
    {
        $this->testMode = $testMode;

        return $this;
    }
}
