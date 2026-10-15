<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\InvoiceSequence;
use c975L\PaymentBundle\Repository\InvoiceSequenceRepository;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;

// The invoice of a paid order and the credit note cancelling it once it is taken back: each numbered once in a gapless series of its own, drawn on demand from what was frozen on the order so it stays reproducible for six years - a B2C invoice, not a Factur-X document
class InvoiceService
{
    // What a number looks like: the prefix a shop sets, the year, and the rank inside that year
    private const string FORMAT = '%s%d-%04d';

    public function __construct(
        private readonly InvoiceSequenceRepository $invoiceSequenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigServiceInterface $configService,
        private readonly PdfGeneratorInterface $pdfGenerator,
        private readonly VatCalculator $vatCalculator,
    ) {
    }

    /**
     * Numbers a paid order, once.
     *
     * Idempotent on purpose although its one caller runs once: a number already held is the invoice the customer
     * was sent, and drawing a second one would leave the first as a gap in the sequence.
     */
    public function assign(Basket $basket): ?string
    {
        if (null !== $basket->getInvoiceNumber()) {
            return $basket->getInvoiceNumber();
        }

        // A rehearsal is not a sale: the number is never drawn rather than drawn and then removed, which would leave the gap this sequence must not hold
        if ($basket->isTestMode()) {
            return null;
        }

        $year = (int) new \DateTime()->format('Y');
        $number = sprintf(self::FORMAT, $this->prefix(), $year, $this->invoiceSequenceRepository->next($year));

        $basket->setInvoiceNumber($number);
        $basket->setInvoiceDate(new \DateTime());
        // Frozen in the same breath as the number: the two are the document, and an invoice numbered without its issuer is one that will be reissued under whoever the shop has become since. Frozen as the configuration holds it and not as a template printed it, the formatting being the renderer's business (see the invoice template) - a value frozen already escaped would be escaped a second time on the way out
        $basket->setInvoiceIssuer(
            $this->config('site-owner'),
            $this->config('site-address'),
            $this->config('site-contact-email'),
            (string) $this->configService->get('shop-invoice-mentions'),
        );
        $this->entityManager->flush();

        return $number;
    }

    // Numbers the credit note of an order taken back, once like assign(), and only for an order that was invoiced - which keeps a never-invoiced rehearsal out of this series
    public function assignCreditNote(Basket $basket): ?string
    {
        if (null === $basket->getInvoiceNumber() || null !== $basket->getCreditNoteNumber()) {
            return $basket->getCreditNoteNumber();
        }

        $year = (int) new \DateTime()->format('Y');
        $number = sprintf(self::FORMAT, $this->prefix('shop-credit-note-prefix', 'AV'), $year, $this->invoiceSequenceRepository->next($year, InvoiceSequence::KIND_CREDIT_NOTE));

        $basket->setCreditNoteNumber($number);
        $basket->setCreditNoteDate(new \DateTime());
        $this->entityManager->flush();

        return $number;
    }

    // The file, drawn from the order as it stands. Null when the order carries no number - nothing is invoiced before it is paid
    public function pdf(Basket $basket, string $template = '@c975LPayment/invoice/pdf.html.twig'): ?string
    {
        return null === $basket->getInvoiceNumber() ? null : $this->render($basket, $template, false);
    }

    // The credit note, the same template under its own title. Null when the order was never taken back
    public function creditNotePdf(Basket $basket, string $template = '@c975LPayment/invoice/pdf.html.twig'): ?string
    {
        return null === $basket->getCreditNoteNumber() ? null : $this->render($basket, $template, true);
    }

    private function render(Basket $basket, string $template, bool $creditNote): string
    {
        return $this->pdfGenerator->render($template, [
            'basket' => $basket,
            'credit_note' => $creditNote,
            'vat' => $this->vatCalculator->breakdown($basket),
            'seller' => $this->seller($basket),
            'mentions' => $basket->getInvoiceMentions() ?? (string) $this->configService->get('shop-invoice-mentions'),
        ]);
    }

    /**
     * Who the invoice says issued it: what was frozen when it was numbered.
     *
     * Falls back on the configuration as it stands for the orders billed before the shop started freezing anything -
     * those invoices were already being reissued with today's company, and an empty seller block would be worse than a
     * dated one.
     *
     * @return array{owner: string, address: string, email: string}
     */
    private function seller(Basket $basket): array
    {
        return [
            'owner' => $basket->getInvoiceSeller() ?? (string) $this->config('site-owner'),
            'address' => $basket->getInvoiceSellerAddress() ?? (string) $this->config('site-address'),
            'email' => $basket->getInvoiceSellerEmail() ?? (string) $this->config('site-contact-email'),
        ];
    }

    // A setting as the shop filled it in, or nothing at all: a shop numbering its first invoice before stating who it is freezes nothing, so its invoices go on reading the configuration until it is filled in - which "" frozen once would have forbidden for ever
    private function config(string $slug): ?string
    {
        $value = trim((string) $this->configService->get($slug));

        return '' === $value ? null : $value;
    }

    // What the recipient sees the file called, the number being what anybody filing it looks for
    public function filename(Basket $basket): string
    {
        return $this->toFilename((string) $basket->getInvoiceNumber());
    }

    public function creditNoteFilename(Basket $basket): string
    {
        return $this->toFilename((string) $basket->getCreditNoteNumber());
    }

    private function toFilename(string $number): string
    {
        return strtolower(str_replace(['/', '\\', ' '], '-', $number)) . '.pdf';
    }

    private function prefix(string $slug = 'shop-invoice-prefix', string $default = 'FA'): string
    {
        $prefix = trim((string) $this->configService->get($slug));

        return '' === $prefix ? $default : $prefix;
    }
}
