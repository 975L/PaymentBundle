<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\StorePurchaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One purchase made in an app through a store, kept apart from Payment: the store is the merchant of record, so there is nothing to invoice nor any VAT here. The row is what makes a restore harmless and a refund possible - the store's transaction id is met once, and the holder it was delivered to is found back from it when the store announces a refund
#[ORM\Entity(repositoryClass: StorePurchaseRepository::class)]
#[ORM\Table(name: 'payment_store_purchase')]
#[ORM\UniqueConstraint(name: 'store_transaction', columns: ['store', 'transaction_id'])]
class StorePurchase implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $isRefunded = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refunded = null;

    public function __construct(
        // The store's slug (see InAppStoreInterface::getSlug())
        #[ORM\Column(length: 16)]
        private string $store,
        #[ORM\Column(length: 128)]
        private string $transactionId,
        #[ORM\Column(length: 100)]
        private string $productId,
        // Whatever the site calls the owner of what was bought (see InAppProductProviderInterface)
        #[ORM\Column(length: 191)]
        private string $holder,
        // "sandbox" for a test purchase - Apple's review and Google's license testers - which delivers all the same
        #[ORM\Column(length: 16)]
        private string $environment = 'production',
    ) {
        $this->creation = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->store . ' ' . $this->transactionId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStore(): string
    {
        return $this->store;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getHolder(): string
    {
        return $this->holder;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getCreation(): \DateTimeImmutable
    {
        return $this->creation;
    }

    public function isRefunded(): bool
    {
        return $this->isRefunded;
    }

    public function getRefunded(): ?\DateTimeImmutable
    {
        return $this->refunded;
    }

    public function markRefunded(): static
    {
        $this->isRefunded = true;
        $this->refunded = new \DateTimeImmutable();

        return $this;
    }

    // A refund the store reversed: the purchase counts again
    public function markRestored(): static
    {
        $this->isRefunded = false;
        $this->refunded = null;

        return $this;
    }
}
