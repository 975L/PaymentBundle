<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

// What an admin types in the back office is refused when it would break the shop: a discount above the basket or below nothing, a code ending before it starts, a negative tier
class AdminRulesConstraintsTest extends TestCase
{
    // A share above a hundred is refused, a hundred accepted, and an amount is not a share
    public function testADiscountAboveAHundredPercentIsRefused(): void
    {
        $this->assertSame(['value'], $this->paths($this->validate(new Discount()->setKind(Discount::KIND_PERCENTAGE)->setValue(150))));
        $this->assertSame([], $this->paths($this->validate(new Discount()->setKind(Discount::KIND_PERCENTAGE)->setValue(100))));
        $this->assertSame([], $this->paths($this->validate(new Discount()->setKind(Discount::KIND_AMOUNT)->setValue(15000))));
    }

    // A code taking nothing off, or adding to the price, is refused whatever its kind
    public function testADiscountTakingNothingOffIsRefused(): void
    {
        $this->assertSame(['value'], $this->paths($this->validate(new Discount()->setKind(Discount::KIND_AMOUNT)->setValue(-1000))));
        $this->assertSame(['value'], $this->paths($this->validate(new Discount()->setKind(Discount::KIND_PERCENTAGE)->setValue(0))));
    }

    // A code ending before it starts is refused, one with either date left empty accepted
    public function testADiscountEndingBeforeItStartsIsRefused(): void
    {
        $this->assertSame(['validUntil'], $this->paths($this->validate(new Discount()->setValue(10)->setValidFrom(new \DateTime('2026-12-01'))->setValidUntil(new \DateTime('2026-11-01')))));
        $this->assertSame([], $this->paths($this->validate(new Discount()->setValue(10)->setValidFrom(new \DateTime('2026-11-01'))->setValidUntil(new \DateTime('2026-12-01')))));
        $this->assertSame([], $this->paths($this->validate(new Discount()->setValue(10)->setValidFrom(new \DateTime('2026-11-01')))));
    }

    // A negative price or weight on a tier is refused through its zone, the screen editing both at once
    public function testANegativeTierIsRefusedThroughItsZone(): void
    {
        $zone = new ShippingZone()->addRate(new ShippingRate()->setPrice(-100)->setMaxWeight(-1));

        $this->assertSame(['rates[0].maxWeight', 'rates[0].price'], $this->paths($this->validate($zone)));
        $this->assertSame([], $this->paths($this->validate(new ShippingZone()->addRate(new ShippingRate()->setPrice(0)->setMaxWeight(100)))));
    }

    // The entity validated whole, from its attributes
    private function validate(object $entity): ConstraintViolationListInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($entity);
    }

    /** @return list<string> */
    private function paths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        sort($paths);

        return $paths;
    }
}
