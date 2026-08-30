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

// Who the invoice says issued it, printed from what was frozen on the order when it was numbered. A legal_var() creeping back into that block would state whoever the shop has become since, and the file would go on looking right - which is what makes it worth asserting on the template itself.
class InvoiceSellerTest extends TestCase
{
    // The seller block reads the order, never the configuration as it stands
    public function testTheSellerBlockIsPrintedFromTheFrozenIssuer(): void
    {
        $invoice = $this->read();

        $this->assertStringContainsString('{{ seller.owner|raw }}', $invoice);
        $this->assertStringContainsString('{{ seller.address|nl2br }}', $invoice);
        $this->assertStringContainsString('{{ seller.email }}', $invoice);
    }

    // legal_var() resolves the live values, which is exactly what an already issued invoice must not do - read past the comments, the block naming it being what forbids it
    public function testTheInvoiceCallsNoLiveSiteIdentity(): void
    {
        $this->assertStringNotContainsString('legal_var(', $this->code());
    }

    // The mentions are frozen the same way, and printed from the variable the service resolves rather than from the configuration
    public function testTheMentionsArePrintedFromTheResolvedVariable(): void
    {
        $this->assertStringContainsString('{{ mentions }}', $this->read());
    }

    private function read(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/invoice/pdf.html.twig');
    }

    // The template without its comments, a comment naming what must not be called being no call
    private function code(): string
    {
        return (string) preg_replace('/{#.*?#}/s', '', $this->read());
    }
}
