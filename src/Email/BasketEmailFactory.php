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
use c975L\UiBundle\Service\EmailTemplateRenderer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// Builds the EmailSendRequest every basket email shares - same envelope, same subject shape - so the sending itself is left to UiBundle's EmailService. Deliberately not an interface: it carries no extension point, unlike the EmailServiceInterface it replaces
class BasketEmailFactory
{
    /**
     * The fragments a basket email can hold, each rendered by a template of templates/emails/slots/ and each
     * responsible for coming out empty when it has nothing to show - an EmailBlock naming an empty slot renders
     * nothing at all, which is what keeps an order carrying no gift card from printing a blank row.
     */
    private const array SLOTS = [
        'order_link',
        'items',
        'counterparts',
        'customer_message',
        'gift_cards',
        'gift_cards_shared',
        'gift_card_message',
        'digital_items',
        'download_links',
        'delivery',
        'account_invitation',
        'reminder_unsubscribe',
    ];

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly EmailTemplateRenderer $emailTemplateRenderer,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
    ) {
    }

    /**
     * Builds the request for one basket email.
     *
     * Always composed from the EmailTemplate of that name, in the language the order was placed in: the site's own
     * row when it has one, the wording PaymentEmailTemplateProvider declares when it does not (see
     * EmailTemplateRenderer::renderNamed). This bundle ships no Twig body beside them any more - a second copy of
     * the same sentences is a second copy to keep in step, and it was already drifting.
     *
     * @param array<string, mixed> $context on top of "basket", what the slots need
     * @param ?string              $to      an address other than the buyer's, for the one e-mail this bundle sends
     *                                      to somebody who never ordered anything: whoever a gift card was bought for
     *
     * @throws \LogicException when neither exists, which no installed PaymentBundle can produce: the declaration
     *                         is this class's own, so its absence means the bundle is half-installed and a silent
     *                         blank order confirmation is the worse of the two answers
     */
    public function create(Basket $basket, string $subjectKey, string $template, array $context = [], ?string $to = null): EmailSendRequest
    {
        $html = $this->emailTemplateRenderer->renderNamed(
            $template,
            $this->variables($basket, $context),
            $basket->getLocale(),
        );

        if (null === $html) {
            throw new \LogicException(sprintf('No email template named "%s" is declared or stored, so this email has no body to send.', $template));
        }

        return new EmailSendRequest(
            subject: $this->buildSubject($subjectKey, $basket),
            context: [],
            html: $html,
            from: $this->config('shop-email-from'),
            fromName: $this->config('shop-email-from-name'),
            to: $to ?? $basket->getEmail(),
            replyTo: $this->config('shop-email-reply-to'),
            replyToName: $this->config('shop-email-reply-to-name'),
            bcc: $this->config('shop-email-bcc'),
            wrapLayout: false,
            // What that same template says it travels with - the terms of sale a shop attaches to its order confirmations, and whatever else its bundles offer. Ticked in the builder beside the blocks, never decided here
            attachments: $this->emailTemplateRenderer->attachmentsFor($template, ['basket' => $basket] + $context, $basket->getLocale()),
        );
    }

    /**
     * What the composed template is given: the scalars its "{{ key }}" placeholders resolve against, and the
     * fragments its slot blocks stand in for.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar|array<string, mixed>>
     */
    private function variables(Basket $basket, array $context): array
    {
        $slots = [];
        foreach (self::SLOTS as $name) {
            $slots[$name] = trim($this->twig->render(
                '@c975LPayment/emails/slots/' . $name . '.html.twig',
                ['basket' => $basket] + $context
            ));
        }

        // Only the scalars of the context travel as placeholders: the rest is what the slots above were rendered from
        $scalars = array_filter($context, is_scalar(...));

        return $scalars + [
            'order_number' => (string) $basket->getNumber(),
            'slots' => $slots,
        ];
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
