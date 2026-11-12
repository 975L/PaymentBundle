<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The total a customer reads in their history is what they paid, delivery and discount included, never the articles' subtotal
class CustomerOrdersTotalTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function templates(): iterable
    {
        yield ['templates/customer/orders.html.twig'];
        yield ['templates/customer/_account_section.html.twig'];
    }

    // Read off the payable amount, the subtotal leaving out the delivery and the code
    #[DataProvider('templates')]
    public function testTheTotalIsWhatWasPaid(string $path): void
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        $this->assertStringContainsString('(basket.payable / 100)|format_currency(basket.currency)', $template);
        $this->assertStringNotContainsString('basket.total', $template);
    }
}
