<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\VatCalculator;
use c975L\PaymentBundle\Twig\VatExtension;
use PHPUnit\Framework\TestCase;
use Twig\Attribute\AsTwigFunction;

// The name the templates call is written in an attribute and nowhere else, so it is what a test has to read back: renaming the method silently leaves every page stating no tax at all
class VatExtensionTest extends TestCase
{
    // The basket page, the order page and the confirmation email all call payment_vat()
    public function testTheFunctionIsRegisteredUnderTheNameTheTemplatesCall(): void
    {
        $attributes = new \ReflectionMethod(VatExtension::class, 'vat')->getAttributes(AsTwigFunction::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('payment_vat', $attributes[0]->getArguments()[0]);
    }

    // Nothing is computed here: the breakdown is the calculator's, handed straight back
    public function testTheBreakdownIsTheCalculatorsOwn(): void
    {
        $basket = new Basket();
        $breakdown = ['rates' => [], 'amount' => 1667];

        $calculator = $this->createMock(VatCalculator::class);
        $calculator->expects($this->once())
            ->method('breakdown')
            ->with($basket)
            ->willReturn($breakdown);

        $this->assertSame($breakdown, new VatExtension($calculator)->vat($basket));
    }
}
