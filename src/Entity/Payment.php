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
use c975L\PaymentBundle\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment_payment')]
class Payment implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $isFinished = false;

    #[ORM\Column]
    private ?int $amount = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    // Which provider charged this payment (see PaymentGatewayInterface::getSlug()), the two columns below holding whatever that one calls its transaction and its method
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $gateway = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $paymentMethod = null;

    // What the provider calls the checkout opened for this payment, kept only until it is paid or called off: it is what lets an edited basket expire the checkout it had already opened
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gatewayReference = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $modification = null;

    #[ORM\OneToOne(mappedBy: 'payment')]
    private ?Basket $basket = null;

    #[ORM\ManyToOne]
    private ?UserInterface $user = null;

    public function __toString(): string
    {
        return (string) $this->id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isFinished(): ?bool
    {
        return $this->isFinished;
    }

    public function setFinished(bool $isFinished): static
    {
        $this->isFinished = $isFinished;

        return $this;
    }

    public function setAmount(?int $amount)
    {
        $this->amount = $amount;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setCurrency(?string $currency)
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getCurrency(): ?string
    {
        return strtoupper($this->currency);
    }

    public function setGateway(?string $gateway)
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function setTransactionId(?string $transactionId)
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setPaymentMethod(?string $paymentMethod)
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
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

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): static
    {
        // unset the owning side of the relation if necessary
        if (null === $basket && null !== $this->basket) {
            $this->basket->setPayment(null);
        }

        // set the owning side of the relation if necessary
        if (null !== $basket && $basket->getPayment() !== $this) {
            $basket->setPayment($this);
        }

        $this->basket = $basket;

        return $this;
    }

    public function getGatewayReference(): ?string
    {
        return $this->gatewayReference;
    }

    public function setGatewayReference(?string $gatewayReference): static
    {
        $this->gatewayReference = $gatewayReference;

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
}
