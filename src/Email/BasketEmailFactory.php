<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Email;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\UiBundle\Model\EmailSendRequest;
use Symfony\Contracts\Translation\TranslatorInterface;

// Builds the EmailSendRequest every basket email shares - same envelope, same subject shape - so the sending itself is left to UiBundle's EmailService. Deliberately not an interface: it carries no extension point, unlike the EmailServiceInterface it replaces
class BasketEmailFactory
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Builds the request for one basket email, its body template rendered alone and wrapped by the site's email layout.
     *
     * @param array<string, mixed> $context on top of "basket", what the body template needs
     */
    public function create(Basket $basket, string $subjectKey, string $template, array $context = []): EmailSendRequest
    {
        return new EmailSendRequest(
            subject: $this->buildSubject($subjectKey, $basket),
            context: ['basket' => $basket] + $context,
            template: '@c975LPayment/emails/' . $template . '.html.twig',
            from: $this->config('shop-email-from'),
            fromName: $this->config('shop-email-from-name'),
            to: $basket->getEmail(),
            replyTo: $this->config('shop-email-reply-to'),
            replyToName: $this->config('shop-email-reply-to-name'),
            bcc: $this->config('shop-email-bcc'),
            wrapLayout: true,
        );
    }

    // "Shop <name> - <what this email is about> - <order number>", the shape every basket email has had since v5
    private function buildSubject(string $subjectKey, Basket $basket): string
    {
        return $this->translator->trans('label.shop', [], 'payment')
            . ' ' . $this->configService->get('shop-name')
            . ' - ' . $this->translator->trans($subjectKey, [], 'payment')
            . ' - ' . $basket->getNumber();
    }

    // A key left blank comes back as null rather than as an empty string, so UiBundle falls back on the site-wide "email-*" address instead of building a broken one
    private function config(string $key): ?string
    {
        return $this->configService->get($key) ?: null;
    }
}
