<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use c975L\PaymentBundle\Repository\ShippingZoneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// A group of countries posted at the same tariff, written by hand in the back office. The countries are held once here rather than on every weight tier, so a country added to a zone is typed once and no tier is left behind
#[ORM\Entity(repositoryClass: ShippingZoneRepository::class)]
#[ORM\Table(name: 'payment_shipping_zone')]
class ShippingZone implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // What the shop calls it - "France", "Union européenne", "Reste du monde". Read by nobody but the admin: what a parcel is priced on is the country list below
    #[ORM\Column(length: 60)]
    private ?string $name = null;

    /**
     * The ISO 3166-1 alpha-2 codes this zone posts to, as CountryType stores them on the order.
     *
     * Left empty on the catch-all zone, the one a country named in no other zone falls into - a shop posting
     * everywhere at one tariff writes that zone and nothing else. Two empty zones is a shop's own mistake, and the
     * first one found answers: the health check says so rather than the resolver picking a winner in silence.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $countries = [];

    // The switch that takes a zone out of use without deleting it, and so without losing the tiers written under it. An inactive zone is not the catch-all either: it is simply not there
    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, ShippingRate> */
    #[ORM\OneToMany(targetEntity: ShippingRate::class, mappedBy: 'zone', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['maxWeight' => 'ASC'])]
    private Collection $rates;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->rates = new ArrayCollection();
        $this->creation = new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /**
     * @param list<string> $countries
     */
    public function setCountries(array $countries): static
    {
        // Held upper-cased and without duplicates, the comparison against an order's country being made on the code and never on what an admin typed
        $this->countries = array_values(array_unique(array_filter(array_map(
            static fn (string $country): string => mb_strtoupper(trim($country)),
            $countries,
        ))));

        return $this;
    }

    // Whether this zone is the one a country named nowhere else falls into
    public function isCatchAll(): bool
    {
        return [] === $this->countries;
    }

    public function holdsCountry(?string $country): bool
    {
        return null !== $country && \in_array(mb_strtoupper(trim($country)), $this->countries, true);
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

    /**
     * @return Collection<int, ShippingRate>
     */
    public function getRates(): Collection
    {
        return $this->rates;
    }

    public function addRate(ShippingRate $rate): static
    {
        if (!$this->rates->contains($rate)) {
            $this->rates->add($rate);
            $rate->setZone($this);
        }

        return $this;
    }

    public function removeRate(ShippingRate $rate): static
    {
        if ($this->rates->removeElement($rate) && $rate->getZone() === $this) {
            $rate->setZone(null);
        }

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
}
