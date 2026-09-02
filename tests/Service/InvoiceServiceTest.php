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

    // An invoice has to be reproducible as it was issued for six years, so who issued it is copied onto the order and not read back off a configuration the shop is free to change
    public function testTheSellerIsFrozenWhenTheOrderIsNumbered(): void
    {
        $basket = new Basket();
        $this->service()->assign($basket);

        $this->assertSame('975L', $basket->getInvoiceSeller());
        $this->assertSame("12 rue des Lilas\n74000 Annecy", $basket->getInvoiceSellerAddress());
        $this->assertSame('contact@975l.com', $basket->getInvoiceSellerEmail());
        $this->assertSame('TVA non applicable, art. 293 B du CGI', $basket->getInvoiceMentions());
    }

    // A shop numbering its first invoice before saying who it is freezes nothing rather than an empty seller block: "" frozen once would have printed a blank issuer for ever, even after the configuration was filled in
    public function testAShopThatHasNotSaidWhoItIsFreezesNothing(): void
    {
        $basket = new Basket();
        $this->service(owner: '')->assign($basket);

        $this->assertNull($basket->getInvoiceSeller());
        $this->assertNull($basket->getInvoiceSellerAddress());
        $this->assertNull($basket->getInvoiceSellerEmail());
    }

    // Mentions are the exception: a shop charging no VAT and having nothing to state freezes that emptiness, and its old invoices keep saying nothing rather than picking up whatever it writes the day it crosses the threshold
    public function testMentionsLeftBlankAreFrozenAsBlank(): void
    {
        $basket = new Basket();
        $this->service(mentions: '')->assign($basket);

        $this->assertSame('', $basket->getInvoiceMentions());
    }

    // The whole point: the shop moves, is renamed, crosses the VAT threshold - and the invoices it already issued go on saying what they said
    public function testAnAlreadyNumberedInvoiceIgnoresWhatTheShopHasBecomeSince(): void
    {
        $pdfGenerator = $this->createMock(PdfGeneratorInterface::class);
        $pdfGenerator
            ->expects($this->once())
            ->method('render')
            ->with($this->anything(), $this->callback(function (array $parameters): bool {
                $this->assertSame('Editions Exemple', $parameters['seller']['owner']);
                $this->assertSame('1 rue Exemple, 75000 Paris', $parameters['seller']['address']);
                $this->assertSame('TVA 20%', $parameters['mentions']);

                return true;
            }))
            ->willReturn('%PDF-1.7')
        ;

        $basket = new Basket()->setInvoiceNumber('FA2026-0001');
        $basket->setInvoiceIssuer('Editions Exemple', '1 rue Exemple, 75000 Paris', 'contact@example.com', 'TVA 20%');

        $this->service(pdfGenerator: $pdfGenerator)->pdf($basket);
    }

    // Orders billed before the shop started freezing anything carry nothing, and an empty seller block would be worse on paper than a dated one
    public function testAnInvoiceIssuedBeforeTheFreezeFallsBackOnTheLiveValues(): void
    {
        $pdfGenerator = $this->createMock(PdfGeneratorInterface::class);
        $pdfGenerator
            ->expects($this->once())
            ->method('render')
            ->with($this->anything(), $this->callback(function (array $parameters): bool {
                $this->assertSame('975L', $parameters['seller']['owner']);
                $this->assertSame("12 rue des Lilas\n74000 Annecy", $parameters['seller']['address']);
                $this->assertSame('TVA non applicable, art. 293 B du CGI', $parameters['mentions']);

                return true;
            }))
            ->willReturn('%PDF-1.7')
        ;

        $this->service(pdfGenerator: $pdfGenerator)->pdf(new Basket()->setInvoiceNumber('FA2026-0001'));
    }

    private function service(
        ?InvoiceSequenceRepository $sequence = null,
        ?PdfGeneratorInterface $pdfGenerator = null,
        string $prefix = 'FA',
        string $owner = '975L',
        string $mentions = 'TVA non applicable, art. 293 B du CGI',
    ): InvoiceService {
        if (null === $sequence) {
            $sequence = $this->createStub(InvoiceSequenceRepository::class);
            $sequence->method('next')->willReturn(7);
        }

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['shop-invoice-prefix', $prefix],
            ['shop-invoice-mentions', $mentions],
            ['site-owner', $owner],
            // Multi-line on purpose: a postal address is what the freeze must keep as the shop typed it, the <br> being the template's business
            ['site-address', $owner ? "12 rue des Lilas\n74000 Annecy" : ''],
            ['site-contact-email', $owner ? 'contact@975l.com' : ''],
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
