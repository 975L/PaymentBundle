<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\PaymentBundle\Contract\BasketLine;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BasketRepository::class)]
#[ORM\Table(name: 'payment_basket')]
#[ORM\UniqueConstraint(name: 'uniq_basket_invoice_number', columns: ['invoice_number'])]
#[UniqueEntity('number')]
class Basket implements \Stringable
{
    public const CONTENT_FLAG_DIGITAL = 1;
    public const CONTENT_FLAG_PHYSICAL = 2;
    public const CONTENT_FLAG_CF_SHIPPING = 4;
    public const CONTENT_FLAG_CF_DIGITAL = 8;
    public const CONTENT_FLAG_SERVICE = 16;
    // What a gift card is sold as: money bought in advance, and so the one line a promotional code must not be taken off - a percentage off a card, or a card bought with a card, is a loop the shop pays for (see BasketCodeService::discountBase())
    public const CONTENT_FLAG_GIFT_CARD = 32;

    // Pre-defined flags
    public const FLAG_PRODUCT_MIXED = self::CONTENT_FLAG_DIGITAL | self::CONTENT_FLAG_PHYSICAL; // 3
    public const FLAG_CF_MIXED = self::CONTENT_FLAG_CF_DIGITAL | self::CONTENT_FLAG_CF_SHIPPING; // 12
    public const FLAG_DIGITAL_ONLY = self::CONTENT_FLAG_DIGITAL | self::CONTENT_FLAG_CF_DIGITAL; // 9
    public const FLAG_NEEDS_SHIPPING = self::CONTENT_FLAG_PHYSICAL | self::CONTENT_FLAG_CF_SHIPPING; // 6
    public const FLAG_SERVICE_ONLY = self::CONTENT_FLAG_SERVICE; // 16
    public const FLAG_NO_SHIPPING = self::FLAG_DIGITAL_ONLY | self::FLAG_SERVICE_ONLY; // 25
    public const FLAG_MIXED = self::FLAG_DIGITAL_ONLY | self::FLAG_NEEDS_SHIPPING | self::FLAG_SERVICE_ONLY; // 31

    // The two kinds of code the one input of the basket page accepts, told apart by the service rather than by the customer (see BasketCodeService)
    public const CODE_KIND_DISCOUNT = 'discount';
    public const CODE_KIND_GIFT_CARD = 'gift_card';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $number = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $securityToken = null;

    // The second secret of an order, handed to whoever is asked to pay: it opens what is being bought and nothing of who it is for, where the token above would disclose the recipient's address
    #[ORM\Column(length: 16, nullable: true, unique: true)]
    private ?string $shareToken = null;

    // The third secret, and the only one a basket carries before it becomes an order: it is what a visitor's browser keeps, so a basket filled anonymously is still theirs once their session has been recycled - which PHP does after 24 minutes of inactivity by default, while the basket sits in the database for days (see BasketRecoverySubscriber)
    #[ORM\Column(length: 16, nullable: true, unique: true)]
    private ?string $recoveryToken = null;

    #[ORM\Column]
    private array $items = [];

    // What each provider handed over at validation, keyed by item kind, given back to it once the basket is delivered. Persisted rather than kept in the visitor's session: the provider's webhook confirms the payment on a request that carries no session of theirs, and anything left there would be lost for every customer who does not come back to the site first
    #[ORM\Column(nullable: true)]
    private ?array $checkoutData = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $status = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $total = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $shipping = null;

    // The code the customer typed, kept as it was resolved rather than re-read at display time: a code deleted or expired after the order was paid must not change what that order says it was charged
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $discountCode = null;

    // Which of the two the code turned out to be, one of the CODE_KIND_* above
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $discountKind = null;

    // What it actually took off, in cents - recomputed on every change of the basket (see BasketService::updateTotals()) and frozen once the order is validated
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $discountAmount = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $quantity = null;

    #[ORM\Column(length: 5)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 5)]
    private ?string $currency = null;

    #[ORM\Column(type: 'smallint')]
    private int $contentflags = 0;

    #[ORM\OneToOne(inversedBy: 'basket')]
    private ?Payment $payment = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $modification = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $itemsShipped = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $counterpartsShipped = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $downloaded = null;

    // When the order left the back-office active list: it is still kept for the ten years the accounting obligation asks for, but it has stopped being current business once the legal warranty has run out, and setting it apart is what the CNIL asks for rather than leaving it among the orders being handled
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $archived = null;

    // How many reminders an abandoned basket has already been sent, so the second one is not the first one over again. Counted here rather than read from "modification": the retention pass reads that date to know when the visitor last touched their basket, and a reminder writing to it would push the purge back every time it fires
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $remindersSent = 0;

    // The day the customer asked to hear no more about this order. Null for as long as they have not: a reminder is not prospection but the follow-up of an order they placed themselves and left unpaid, so it goes out without being asked for - and stops the moment the link at the foot of it is clicked
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reminderOptOutAt = null;

    // The language the order was placed in, remembered because the e-mails that follow it are not all sent from the customer's own request: a reminder goes out from a nightly command and a shipping notice from the shopkeeper's click, and neither would know what language to write in. Null on the orders taken before this was kept, which the site's own language answers for
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    // The day the invoice was issued, kept beside its number and never read off "modification": that date moves every time the order is touched - a parcel posted two days later would redate an invoice already in the customer's mailbox, and the same number would then name two documents
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $invoiceDate = null;

    // Whether the checkout was opened while the shop was charging with the provider's test keys. Stamped when the order is frozen and never afterwards - the toggle can be flipped back between the moment an order is validated and the moment it is paid, and what a rehearsal is, is an order charged against test keys. Read by InvoiceService, which numbers no such order: an invoice sequence an accountant reads holds no document for a sale that never happened
    #[ORM\Column]
    private bool $testMode = false;

    // The invoice this order was billed under, drawn once when it is paid and never again: an invoice number is a sequence an accountant reads, not a value recomputed from the order (see InvoiceService::assign()). Null on the orders taken before the shop issued any, and on everything not yet paid
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $invoiceNumber = null;

    // Who issued the invoice, frozen with its number and never looked up again (see InvoiceService::assign()). A shop is renamed, moves, changes its status; an invoice has to be reproducible as it was issued for six years, so the seller block is copied onto the order rather than read back off a configuration that has since moved on. Null on every order billed before the shop started freezing it, which is what the invoice falls back on the live values for
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoiceSeller = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoiceSellerAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $invoiceSellerEmail = null;

    // The registration numbers, the VAT number, or the article exempting the shop from charging any. Frozen for the sharpest reason of the four: a shopkeeper crossing the VAT threshold rewrites these, and every invoice issued before that day must keep saying what it said
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoiceMentions = null;

    // Whoever the gift cards of this order were bought for, and what the buyer wanted said to them. One address per order, not one per card: a shopper buying two cards for the same person is the ordinary case, and asking twice for two lines that go to the same mailbox helps nobody
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $giftCardRecipientEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $giftCardRecipientMessage = null;

    #[ORM\ManyToOne]
    private ?UserInterface $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $message = null;

    // What the basket page's JavaScript is answered with, so the three secrets are taken out of it: they guard the order-tracking page, the shared payment page and the basket itself, and none of them is anything the browser has to be told
    public function toArray(): array
    {
        $vars = get_object_vars($this);
        unset($vars['securityToken'], $vars['shareToken'], $vars['recoveryToken']);
        // Read through the getter rather than off the property, so the page is handed the same shape the templates are
        $vars['items'] = $this->getItems();

        return $vars;
    }

    public function __toString(): string
    {
        return $this->number ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getRecoveryToken(): ?string
    {
        return $this->recoveryToken;
    }

    public function setRecoveryToken(?string $recoveryToken): static
    {
        $this->recoveryToken = $recoveryToken;

        return $this;
    }

    public function getSecurityToken(): ?string
    {
        return $this->securityToken;
    }

    public function setSecurityToken(?string $securityToken): self
    {
        $this->securityToken = $securityToken;

        return $this;
    }

    // Every line brought up to the shape the code reads, which is the one place an order written years ago is caught up with (see BasketLine::normalize())
    public function getItems(): array
    {
        return array_map(
            static fn (array $itemsOfThisKind): array => array_map(BasketLine::normalize(...), $itemsOfThisKind),
            $this->items
        );
    }

    public function setItems(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    // Whether this basket holds that one item - what a paywall asks of a paid order, the items being stored as items[kind][id]
    public function holdsItem(string $kind, int | string $itemId): bool
    {
        return isset($this->items[$kind][$itemId]);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by item kind, empty when no provider handed anything over
     */
    public function getCheckoutData(): array
    {
        return $this->checkoutData ?? [];
    }

    /**
     * @param array<string, array<string, mixed>> $checkoutData
     */
    public function setCheckoutData(array $checkoutData): static
    {
        $this->checkoutData = [] === $checkoutData ? null : $checkoutData;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getShipping(): ?int
    {
        return $this->shipping;
    }

    public function setShipping(int $shipping): static
    {
        $this->shipping = $shipping;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getContentFlags(): int
    {
        return $this->contentflags;
    }

    public function setContentFlags(int $contentflags): static
    {
        $this->contentflags = $contentflags;

        return $this;
    }

    public function getCreation(): ?\DateTimeInterface
    {
        return $this->creation;
    }

    public function setCreation(\DateTimeInterface $creation): static
    {
        $this->creation = $creation;

        return $this;
    }

    public function getModification(): ?\DateTimeInterface
    {
        return $this->modification;
    }

    public function setModification(\DateTimeInterface $modification): static
    {
        $this->modification = $modification;

        return $this;
    }

    public function getItemsShipped(): ?\DateTimeInterface
    {
        return $this->itemsShipped;
    }

    public function setItemsShipped(?\DateTimeInterface $itemsShipped): static
    {
        $this->itemsShipped = $itemsShipped;

        return $this;
    }

    public function getCounterpartsShipped(): ?\DateTimeInterface
    {
        return $this->counterpartsShipped;
    }

    public function setCounterpartsShipped(?\DateTimeInterface $counterpartsShipped): static
    {
        $this->counterpartsShipped = $counterpartsShipped;

        return $this;
    }

    public function getDownloaded(): ?\DateTimeInterface
    {
        return $this->downloaded;
    }

    public function setDownloaded(?\DateTimeInterface $downloaded): static
    {
        $this->downloaded = $downloaded;

        return $this;
    }

    public function getArchived(): ?\DateTimeInterface
    {
        return $this->archived;
    }

    public function setArchived(?\DateTimeInterface $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function getRemindersSent(): int
    {
        return $this->remindersSent;
    }

    public function setRemindersSent(int $remindersSent): static
    {
        $this->remindersSent = $remindersSent;

        return $this;
    }

    public function getReminderOptOutAt(): ?\DateTimeInterface
    {
        return $this->reminderOptOutAt;
    }

    public function setReminderOptOutAt(?\DateTimeInterface $reminderOptOutAt): static
    {
        $this->reminderOptOutAt = $reminderOptOutAt;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(?UserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getDiscountCode(): ?string
    {
        return $this->discountCode;
    }

    public function setDiscountCode(?string $discountCode): static
    {
        $this->discountCode = $discountCode;

        return $this;
    }

    public function getDiscountKind(): ?string
    {
        return $this->discountKind;
    }

    public function setDiscountKind(?string $discountKind): static
    {
        $this->discountKind = $discountKind;

        return $this;
    }

    public function getDiscountAmount(): int
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(int $discountAmount): static
    {
        $this->discountAmount = max(0, $discountAmount);

        return $this;
    }

    // What is actually charged: the items, the shipping, less whatever the code took off. Never below zero - a card worth more than the order pays it in full and keeps the rest for the next one
    public function getPayable(): int
    {
        return max(0, (int) $this->total + (int) $this->shipping - $this->discountAmount);
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

    // An order somebody else is being asked to settle, which is the only case a payment page is opened by anyone but its customer
    public function isShared(): bool
    {
        return null !== $this->shareToken;
    }

    public function getGiftCardRecipientEmail(): ?string
    {
        return $this->giftCardRecipientEmail;
    }

    public function setGiftCardRecipientEmail(?string $giftCardRecipientEmail): static
    {
        $this->giftCardRecipientEmail = $giftCardRecipientEmail;

        return $this;
    }

    public function getGiftCardRecipientMessage(): ?string
    {
        return $this->giftCardRecipientMessage;
    }

    public function setGiftCardRecipientMessage(?string $giftCardRecipientMessage): static
    {
        $this->giftCardRecipientMessage = $giftCardRecipientMessage;

        return $this;
    }

    // Whether this order has somebody other than the buyer to write to: a card was bought, and an address was given for it
    public function hasGiftCardRecipient(): bool
    {
        return null !== $this->giftCardRecipientEmail && '' !== $this->giftCardRecipientEmail
            && 0 !== ($this->contentflags & self::CONTENT_FLAG_GIFT_CARD);
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

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getInvoiceSeller(): ?string
    {
        return $this->invoiceSeller;
    }

    public function getInvoiceSellerAddress(): ?string
    {
        return $this->invoiceSellerAddress;
    }

    public function getInvoiceSellerEmail(): ?string
    {
        return $this->invoiceSellerEmail;
    }

    public function getInvoiceMentions(): ?string
    {
        return $this->invoiceMentions;
    }

    // Written by InvoiceService::assign() alone, at the same moment as the number, and never afterwards - the four together are what makes the document reproducible, so they are set as one rather than one setter at a time
    public function setInvoiceIssuer(?string $seller, ?string $address, ?string $email, ?string $mentions): static
    {
        $this->invoiceSeller = $seller;
        $this->invoiceSellerAddress = $address;
        $this->invoiceSellerEmail = $email;
        $this->invoiceMentions = $mentions;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeInterface
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeInterface $invoiceDate): static
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }
}
