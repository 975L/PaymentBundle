<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\ShippingRateRepository;
use Doctrine\ORM\Mapping as ORM;

// One weight tier of one zone: what a parcel costs up to that weight. A zone's tiers are read smallest first, and the first one the parcel fits in is the one it is charged at
#[ORM\Entity(repositoryClass: ShippingRateRepository::class)]
#[ORM\Table(name: 'payment_shipping_rate')]
class ShippingRate implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShippingZone::class, inversedBy: 'rates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ShippingZone $zone = null;

    // The heaviest parcel this tier covers, in grams, the weight itself included - a 1000 g tier posts a parcel of exactly 1000 g. Null is the tier with no ceiling, the one that catches everything above the others, and a zone without it refuses to post what none of its tiers covers
    #[ORM\Column(nullable: true)]
    private ?int $maxWeight = null;

    // What the shop charges for that parcel, in cents like every other amount here. Zero is a legitimate tier: it is how a shop posts light parcels free without touching the franco threshold
    #[ORM\Column]
    private int $price = 0;

    public function __toString(): string
    {
        return sprintf('%s / %s', $this->zone?->getName() ?? '', null === $this->maxWeight ? '∞' : $this->maxWeight . ' g');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZone(): ?ShippingZone
    {
        return $this->zone;
    }

    public function setZone(?ShippingZone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getMaxWeight(): ?int
    {
        return $this->maxWeight;
    }

    public function setMaxWeight(?int $maxWeight): static
    {
        $this->maxWeight = $maxWeight;

        return $this;
    }

    // Whether a parcel of that weight is posted at this tier
    public function covers(int $weight): bool
    {
        return null === $this->maxWeight || $weight <= $this->maxWeight;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }
}
