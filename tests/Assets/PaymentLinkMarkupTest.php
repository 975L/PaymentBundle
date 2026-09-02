<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The three places a basket line is drawn, read against a line hanging under no catalogue entry.
 *
 * A payment link is the first kind this bundle sells that names no parent: no page to link to, no picture, no
 * slug. Nothing here renders Twig - what is guarded is that none of these templates dereferences a catalogue
 * entry it may not have, which is a 500 on the page a payer reaches after being charged.
 */
class PaymentLinkMarkupTest extends TestCase
{
    // The row of the basket, the payer's page and the order emails all rendering this same component
    public function testTheBasketRowAsksForAPageToLinkToOnlyWhenTheLineNamesOne(): void
    {
        $template = $this->read('templates/components/Basket/Item.html.twig');

        $this->assertStringContainsString('{% set catalogued = item.parent.slug is defined and item.parent.slug is not empty %}', $template);
        $this->assertStringContainsString('{% if linked %}', $template);
        // The route is built inside the guard: "payment_link_display" was never declared, and asking for it is what would raise
        $this->assertStringContainsString('(catalogued ? url(item.type ~ "_display"', $template);
        // A line naming its own page is sent there instead, no route being guessed from its kind - which is how a photograph, read under its gallery and its own slug, is linked at all
        $this->assertStringContainsString('{% set itemUrl = ownUrl ? item.parent.url :', $template);
        $this->assertStringContainsString('{% set linked = itemUrl is not null %}', $template);
    }

    // An accounting document, where an empty pair of brackets is what a customer queries
    public function testTheInvoiceNamesALineByItsLabelWhenItHangsUnderNothing(): void
    {
        $template = $this->read('templates/invoice/pdf.html.twig');

        $this->assertStringContainsString("item.parent.title|default('') is empty ? item.item.title", $template);
        $this->assertStringNotContainsString('<td>{{ item.parent.title }} ({{ item.item.title }})</td>', $template);
    }

    // The address the admin came for, shown once and copied - never printed again on a screen they could take it from
    public function testTheBackOfficeFormShowsTheAddressItJustWrote(): void
    {
        $template = $this->read('templates/management/payment_link.html.twig');

        $this->assertStringContainsString("app.flashes('payment_link_url')", $template);
        $this->assertStringContainsString("'label.payment_link'|trans({}, 'payment')", $template);
    }

    private function read(string $path): string
    {
        $file = \dirname(__DIR__, 2) . '/' . $path;
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }
}
