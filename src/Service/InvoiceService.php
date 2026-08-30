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
use c975L\PaymentBundle\Repository\InvoiceSequenceRepository;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The invoice of a paid order: its number, drawn once, and the file that states it.
 *
 * Numbered when the order is paid and nowhere else - `paid()` runs once per order, the database having said so
 * (see BasketRepository::claimPaid()) - so the sequence follows the orders that were actually settled and holds
 * no gap. An order that was never paid never gets a number, which is what keeps the sequence continuous rather
 * than full of holes where a basket was abandoned - and neither does one checked out against the provider's test
 * keys, a rehearsal being no sale to bill.
 *
 * The file is drawn on demand and kept nowhere: it says what the order says, and the order is the record. Drawing
 * it again next year from the same row gives the same document, which a stored copy could not promise - but only
 * because the seller block and the legal mentions are copied onto the order when it is numbered. Read back off the
 * configuration they would be today's, and a shop that has moved or crossed the VAT threshold would be reissuing its
 * old invoices with the wrong company on them, which the six years an invoice must stay reproducible do not allow.
 *
 * This is a B2C invoice. Selling to businesses is another matter entirely - a Factur-X document (PDF/A-3 with its
 * XML inside) sent through an approved platform - and nothing here pretends to be one.
 */
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

    // The file, drawn from the order as it stands. Null when the order carries no number - nothing is invoiced before it is paid
    public function pdf(Basket $basket, string $template = '@c975LPayment/invoice/pdf.html.twig'): ?string
    {
        if (null === $basket->getInvoiceNumber()) {
            return null;
        }

        return $this->pdfGenerator->render($template, [
            'basket' => $basket,
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
        return strtolower(str_replace(['/', '\\', ' '], '-', (string) $basket->getInvoiceNumber())) . '.pdf';
    }

    private function prefix(): string
    {
        $prefix = trim((string) $this->configService->get('shop-invoice-prefix'));

        return '' === $prefix ? 'FA' : $prefix;
    }
}
