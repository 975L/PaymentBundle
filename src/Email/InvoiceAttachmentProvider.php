<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Email;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\UiBundle\Contract\EmailAttachmentProviderInterface;
use c975L\UiBundle\Model\EmailAttachment;

use function Symfony\Component\Translation\t;

// Offers the order's invoice as something the shop can tick on any of its emails - the confirmation being the one it belongs on, though nothing here decides that
class InvoiceAttachmentProvider implements EmailAttachmentProviderInterface
{
    private const string KIND = 'payment:invoice';

    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {
    }

    public function getAttachmentKinds(): array
    {
        return [self::KIND => t('label.invoice', [], 'payment')];
    }

    public function createAttachment(string $kind, array $context): ?EmailAttachment
    {
        $basket = $context['basket'] ?? null;

        // Nothing to attach rather than an error: this provider is offered on every email a site sends, and most of them are about no order at all. An order not yet paid carries no number, and InvoiceService answers null for it
        if (self::KIND !== $kind || !$basket instanceof Basket) {
            return null;
        }

        $pdf = $this->invoiceService->pdf($basket);

        return null === $pdf ? null : new EmailAttachment($this->invoiceService->filename($basket), $pdf);
    }
}
