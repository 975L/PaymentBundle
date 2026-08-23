<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;

// The rate an article is taxed at, stated everywhere its line is drawn - the basket page, the order emails that render the very same component, and the invoice. Read through payment_vat_rate() at each of them and never off the snapshot, so a gift card and a line taxed at no rate say the same thing in all three places.
class VatPerLineTest extends TestCase
{
    // The component the basket page and the order emails both render: one place to state the rate, one place for it to be wrong
    public function testTheLineOfABasketStatesItsOwnRate(): void
    {
        $component = $this->read('templates/components/Basket/Item.html.twig');

        $this->assertStringContainsString('{% set vatRate = payment_vat_rate(item, type) %}', $component);
        $this->assertStringContainsString("'label.vat_rate'|trans({'%rate%': vatRate|format_number})", $component);
    }

    // The invoice states the rate article by article, which is what an accounting document is read for - the amounts per rate being stated in the recap below it
    public function testTheInvoiceCarriesARateColumn(): void
    {
        $invoice = $this->read('templates/invoice/pdf.html.twig');

        $this->assertStringContainsString('{% set vatRate = payment_vat_rate(item, type) %}', $invoice);
        $this->assertStringContainsString("<th class=\"amount\">{{ 'label.vat'|trans }}</th>", $invoice);
        $this->assertStringNotContainsString('colspan="3"', $invoice, 'The foot still spans the table as it was before the column was added.');
    }

    // Null and not zero is what the calculator answers, and the templates have to read it as such: "{% if vatRate %}" would print nothing on a line taxed at no rate either, but "0 %" is a statement, and an "is not null" test is what says the difference is deliberate
    public function testALineTaxedAtNoRateIsLeftBlankRatherThanPrintedAsZero(): void
    {
        $this->assertStringContainsString('{% if vatRate is not null %}', $this->read('templates/components/Basket/Item.html.twig'));
        $this->assertStringContainsString('{% if vatRate is not null %}', $this->read('templates/invoice/pdf.html.twig'));
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $path);
    }
}
