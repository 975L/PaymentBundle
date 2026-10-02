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
use c975L\PaymentBundle\Twig\MatomoOrderExtension;
use PHPUnit\Framework\TestCase;
use Twig\Attribute\AsTwigFunction;

// Matomo reads amounts in units and takes whatever it is handed: a total left in cents multiplies every sale of the reports by a hundred, and nothing anywhere raises an error
class MatomoOrderExtensionTest extends TestCase
{
    // The name is written in an attribute and nowhere else, so it is what the component calling it relies on
    public function testTheFunctionIsRegisteredUnderTheNameTheComponentCalls(): void
    {
        $attributes = new \ReflectionMethod(MatomoOrderExtension::class, 'order')->getAttributes(AsTwigFunction::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('payment_matomo_order', $attributes[0]->getArguments()[0]);
    }

    // Every amount in units, the grand total being what was actually paid once shipping and discount are counted
    public function testTheOrderIsHandedOverInUnits(): void
    {
        $basket = $this->basket();
        $basket->setDiscountAmount(500);

        $order = $this->extension(1667)->order($basket);

        $this->assertSame('20261002-123456', $order['id']);
        $this->assertSame(100.0, $order['subTotal']);
        $this->assertSame(6.9, $order['shipping']);
        $this->assertSame(5.0, $order['discount']);
        $this->assertSame(101.9, $order['grandTotal']);
        $this->assertSame(16.67, $order['tax']);
    }

    // One entry per line, named as the checkout names it, its kind as category so each selling bundle reads apart
    public function testEachLineBecomesAnItemOfItsKind(): void
    {
        $items = $this->extension(0)->order($this->basket())['items'];

        $this->assertSame([
            ['sku' => 'product-12', 'name' => 'Book (Hardcover)', 'category' => 'product', 'price' => 30.0, 'quantity' => 2],
            ['sku' => 'paymentLink-7', 'name' => 'Donation', 'category' => 'paymentLink', 'price' => 40.0, 'quantity' => 1],
        ], $items);
    }

    private function basket(): Basket
    {
        return new Basket()
            ->setNumber('20261002-123456')
            ->setTotal(10000)
            ->setShipping(690)
            ->setItems([
                'product' => [12 => ['quantity' => 2, 'total' => 6000, 'item' => ['title' => 'Hardcover', 'price' => 3000], 'parent' => ['title' => 'Book']]],
                'paymentLink' => [7 => ['quantity' => 1, 'total' => 4000, 'item' => ['title' => 'Donation', 'price' => 4000]]],
            ]);
    }

    private function extension(int $vat): MatomoOrderExtension
    {
        $calculator = $this->createStub(VatCalculator::class);
        $calculator->method('breakdown')->willReturn(['rates' => [], 'amount' => $vat]);

        return new MatomoOrderExtension($calculator);
    }
}
