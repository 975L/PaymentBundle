<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Email;

use c975L\PaymentBundle\Email\InvoiceAttachmentProvider;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

// What a shop ticks in the email builder to have its orders travel with their invoice
class InvoiceAttachmentProviderTest extends TestCase
{
    public function testTheInvoiceIsOfferedUnderANamespacedKind(): void
    {
        $this->assertSame(['payment:invoice'], array_keys($this->provider()->getAttachmentKinds()));
    }

    public function testItDrawsTheInvoiceOfTheOrderBeingWrittenAbout(): void
    {
        $attachment = $this->provider()->createAttachment('payment:invoice', ['basket' => new Basket()]);

        $this->assertSame('fa2026-0001.pdf', $attachment?->filename);
        $this->assertSame('%PDF-1.7', $attachment?->content);
    }

    // This provider is offered on every email a site sends, and most of them are about no order at all
    public function testAnEmailAboutNoOrderCarriesNothing(): void
    {
        $this->assertNull($this->provider()->createAttachment('payment:invoice', []));
    }

    public function testAKindItDoesNotOwnIsDeclined(): void
    {
        $this->assertNull($this->provider()->createAttachment('legal:france/terms-of-sales', ['basket' => new Basket()]));
    }

    // An order not yet paid carries no number, so there is no invoice to attach and no error to raise about it
    public function testAnOrderWithNoInvoiceYetCarriesNothing(): void
    {
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('pdf')->willReturn(null);

        $this->assertNull($this->provider($invoiceService)->createAttachment('payment:invoice', ['basket' => new Basket()]));
    }

    private function provider(?InvoiceService $invoiceService = null): InvoiceAttachmentProvider
    {
        if (null === $invoiceService) {
            $invoiceService = $this->createStub(InvoiceService::class);
            $invoiceService->method('pdf')->willReturn('%PDF-1.7');
            $invoiceService->method('filename')->willReturn('fa2026-0001.pdf');
        }

        return new InvoiceAttachmentProvider($invoiceService);
    }
}
