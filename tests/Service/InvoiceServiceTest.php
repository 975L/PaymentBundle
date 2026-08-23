<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Repository\InvoiceSequenceRepository;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\VatCalculator;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

// The number an accountant reads: drawn once when the order is paid, never drawn twice, and never recomputed from the order
class InvoiceServiceTest extends TestCase
{
    public function testANumberStatesThePrefixTheYearAndTheRank(): void
    {
        $number = $this->service()->assign(new Basket());

        $this->assertSame(sprintf('FA%d-0007', (int) new \DateTime()->format('Y')), $number);
    }

    public function testAShopSetsItsOwnPrefix(): void
    {
        $this->assertStringStartsWith('INV', (string) $this->service(prefix: 'INV')->assign(new Basket()));
    }

    // A shop that filled nothing in still gets a number rather than one starting with the year alone
    public function testAPrefixLeftBlankFallsBackOnTheDefault(): void
    {
        $this->assertStringStartsWith('FA', (string) $this->service(prefix: '')->assign(new Basket()));
    }

    /**
     * The number already held is the invoice the customer was sent.
     *
     * Drawing a second one would leave the first as a gap in the sequence, which is the one thing an invoice
     * sequence must not have.
     */
    public function testAnOrderAlreadyNumberedKeepsItsNumberAndDrawsNothing(): void
    {
        $sequence = $this->createMock(InvoiceSequenceRepository::class);
        $sequence->expects($this->never())->method('next');

        $basket = new Basket()->setInvoiceNumber('FA2026-0001');

        $this->assertSame('FA2026-0001', $this->service(sequence: $sequence)->assign($basket));
    }

    /**
     * An order checked out against the provider's test keys is billed nothing at all.
     *
     * Drawn and then removed, the number would leave the gap this sequence must not hold - and kept, it would
     * name an invoice for a sale that never happened. So it is never drawn.
     */
    public function testAnOrderCheckedOutInTestModeIsNeverNumbered(): void
    {
        $sequence = $this->createMock(InvoiceSequenceRepository::class);
        $sequence->expects($this->never())->method('next');

        $basket = new Basket()->setTestMode(true);

        $this->assertNull($this->service(sequence: $sequence)->assign($basket));
        $this->assertNull($basket->getInvoiceNumber());
        $this->assertNull($basket->getInvoiceDate());
    }

    public function testTheNumberIsWrittenOnTheOrder(): void
    {
        $basket = new Basket();

        $this->assertSame($this->service()->assign($basket), $basket->getInvoiceNumber());
    }

    /**
     * The invoice carries a date of its own, and not the order's last change.
     *
     * "modification" moves every time the order is touched - the day the parcel goes out, among others - so an
     * invoice reading it would be redated after the customer already had a copy, and the same number would then
     * name two documents.
     */
    public function testTheInvoiceIsDatedTheDayItIsIssuedAndNotTheDayTheOrderLastMoved(): void
    {
        $basket = new Basket();

        $this->service()->assign($basket);
        $issued = $basket->getInvoiceDate();

        $basket->setModification(new \DateTime('+2 days'));

        $this->assertNotNull($issued);
        $this->assertSame($issued, $basket->getInvoiceDate());
        $this->assertSame(new \DateTime()->format('Y-m-d'), $issued->format('Y-m-d'));
    }

    // Nothing is invoiced before it is paid, and an order is numbered when it is paid: no number, no document
    public function testAnOrderWithoutANumberHasNoInvoiceToDraw(): void
    {
        $this->assertNull($this->service()->pdf(new Basket()));
    }

    public function testTheDocumentIsDrawnFromTheOrder(): void
    {
        $pdfGenerator = $this->createStub(PdfGeneratorInterface::class);
        $pdfGenerator->method('render')->willReturn('%PDF-1.7 an invoice');

        $basket = new Basket()->setInvoiceNumber('FA2026-0001');

        $this->assertSame('%PDF-1.7 an invoice', $this->service(pdfGenerator: $pdfGenerator)->pdf($basket));
    }

    // The number is what anybody filing the file looks for, so it is what the file is called
    public function testTheFileIsNamedAfterTheNumber(): void
    {
        $this->assertSame('fa2026-0001.pdf', $this->service()->filename(new Basket()->setInvoiceNumber('FA2026-0001')));
    }

    private function service(
        ?InvoiceSequenceRepository $sequence = null,
        ?PdfGeneratorInterface $pdfGenerator = null,
        string $prefix = 'FA',
    ): InvoiceService {
        if (null === $sequence) {
            $sequence = $this->createStub(InvoiceSequenceRepository::class);
            $sequence->method('next')->willReturn(7);
        }

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['shop-invoice-prefix', $prefix],
            ['shop-invoice-mentions', ''],
        ]);

        return new InvoiceService(
            $sequence,
            $this->createStub(EntityManagerInterface::class),
            $configService,
            $pdfGenerator ?? $this->createStub(PdfGeneratorInterface::class),
            new VatCalculator($this->createStub(BasketItemProviderRegistry::class)),
        );
    }
}
