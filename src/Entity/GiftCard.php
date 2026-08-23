<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\GiftCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Money somebody already paid for, carried by whoever holds the code. Not a Discount with a counter: a discount is a rule the shop writes, where this is a balance that goes down and can be spent over several orders - which is why it is issued by a purchase (see GiftCardService::issue()) and never typed in the back office
#[ORM\Entity(repositoryClass: GiftCardRepository::class)]
#[ORM\Table(name: 'payment_gift_card')]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_share_token', columns: ['share_token'])]
class GiftCard implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Long and random rather than readable: this is a bearer instrument, and anybody able to guess one spends somebody else's money (see GiftCardService::generateCode())
    #[ORM\Column(length: 40)]
    private ?string $code = null;

    // What the address given to whoever the card is for carries, the code itself never being in it: a url travels through browser histories, referrers, chat servers and link previews, and a code read off one of those logs is a balance spent by somebody who never held the card. Nullable: the cards issued before the page existed carry none and are read off their code alone
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $shareToken = null;

    // What it was worth the day it was issued, kept beside the balance so an order can say what was spent out of what
    #[ORM\Column]
    private int $initialAmount = 0;

    // What is left, in the currency's smallest unit. Goes down as orders are settled and never back up: a refund is a payment of its own, not a card being refilled
    #[ORM\Column]
    private int $balance = 0;

    #[ORM\Column(length: 5)]
    private ?string $currency = null;

    // Empty on a card that never expires, which is what the law says of a voucher in most of Europe
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    // The order that paid for it, by its number: what lets the confirmation email of that very order print the codes it bought, and an admin trace a card back to what created it
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $issuedByBasket = null;

    #[ORM\Column]
    private bool $active = true;

    // The picture the card was sold with, copied here rather than pointed at: the visual belongs to whichever bundle sold it, and a card outlives what that catalogue holds - a design withdrawn from sale next month must not blank a card somebody still carries. Same reasoning as the article a basket copies onto itself
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $designImage = null;

    // The words printed on the recto beside the picture and the amount, copied for the same reason
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $designText = null;

    // Whether the code is hidden under a panel to be scratched off, the way a card on a rack is. Not decoration alone: scratched, the code is not written in the page at all and is asked for only once the panel is rubbed off - a page pasted into a chat is unfurled by a robot that fetches it and runs no script
    #[ORM\Column(options: ['default' => true])]
    private bool $scratch = true;

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

    public function setCode(?string $code): static
    {
        $this->code = null === $code ? null : mb_strtoupper(trim($code));

        return $this;
    }

    public function getInitialAmount(): int
    {
        return $this->initialAmount;
    }

    public function setInitialAmount(int $initialAmount): static
    {
        $this->initialAmount = max(0, $initialAmount);

        return $this;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function setBalance(int $balance): static
    {
        $this->balance = max(0, $balance);

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = null === $currency ? null : mb_strtoupper(trim($currency));

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

    public function getIssuedByBasket(): ?string
    {
        return $this->issuedByBasket;
    }

    public function setIssuedByBasket(?string $issuedByBasket): static
    {
        $this->issuedByBasket = $issuedByBasket;

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

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): static
    {
        $this->shareToken = $shareToken;

        return $this;
    }

    public function getDesignImage(): ?string
    {
        return $this->designImage;
    }

    public function setDesignImage(?string $designImage): static
    {
        $this->designImage = $designImage;

        return $this;
    }

    public function getDesignText(): ?string
    {
        return $this->designText;
    }

    public function setDesignText(?string $designText): static
    {
        $this->designText = $designText;

        return $this;
    }

    public function hasScratch(): bool
    {
        return $this->scratch;
    }

    public function setScratch(bool $scratch): static
    {
        $this->scratch = $scratch;

        return $this;
    }

    // What is left to spend, said as the card says it: a card switched off or out of date is worth nothing whatever its balance, and the page showing it must not print a figure the checkout would refuse
    public function isSpendable(): bool
    {
        return $this->active && $this->balance > 0 && (null === $this->validUntil || $this->validUntil >= new \DateTime());
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
