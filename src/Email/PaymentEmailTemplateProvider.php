<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Email;

use c975L\UiBundle\Contract\EmailTemplateProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The six e-mails a shop sends about an order, as templates an admin composes rather than Twig files nobody but a
 * developer can touch.
 *
 * Each is cut along the seam that matters: the sentences, which an admin rewrites, and the fragments the code
 * computes - the order's lines, its delivery address, its download links - which appear here as slot blocks named
 * after templates/emails/slots/ and hold whatever BasketEmailFactory rendered into them. A slot that comes out
 * empty renders nothing, so an order carrying no gift card shows no gap.
 *
 * Only the structure is written here. Every sentence is read from the translation catalogue, which is the one
 * place this bundle's default wording lives: what is seeded into a site's EmailTemplate row on the first
 * c975l:ui:email-templates:ensure, what EmailTemplateRenderer falls back on if that row is ever deleted, and what
 * a translator edits for a language the bundle does not ship yet. An admin's own rewriting happens afterwards, on
 * the row, and is never overwritten by either.
 *
 * Declared and not seeded on the spot: the same declaration is what c975l:ui:email-templates:ensure seeds from and
 * what EmailTemplateHealthCheckProvider reports a site to be missing.
 */
class PaymentEmailTemplateProvider implements EmailTemplateProviderInterface
{
    // The languages this bundle ships a payment catalogue for. Listed rather than read from kernel.enabled_locales: the translator answers every locale, falling back on the default one, so iterating the site's languages would seed a Spanish row holding French sentences
    private const array LOCALES = ['fr', 'en', 'es'];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getEmailTemplates(): array
    {
        $templates = [];
        foreach (self::LOCALES as $locale) {
            foreach ($this->structure($locale) as $name => $blocks) {
                $templates[$name][$locale] = $blocks;
            }
        }

        return $templates;
    }

    /**
     * @return array<string, list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}>>
     */
    private function structure(string $locale): array
    {
        return [
            'confirm_order' => [
                $this->text('label.basket_thanks', $locale),
                $this->slot('order_link'),
                $this->text('label.basket_products_reminder', $locale),
                $this->slot('items'),
                $this->slot('customer_message'),
                $this->slot('gift_cards'),
                $this->slot('digital_items'),
                $this->slot('delivery'),
                $this->slot('account_invitation'),
            ],
            'items_shipped' => [
                $this->text('label.basket_thanks', $locale),
                $this->slot('order_link'),
                $this->text('label.basket_products_reminder', $locale),
                $this->slot('items'),
                $this->slot('delivery'),
            ],
            'counterparts_shipped' => [
                $this->text('label.basket_thanks', $locale),
                $this->slot('order_link'),
                $this->text('label.basket_counterparts_reminder', $locale),
                $this->slot('counterparts'),
                $this->slot('delivery'),
            ],
            'download_information' => [
                $this->slot('order_link'),
                $this->text('label.downloads_available', $locale),
                $this->text('label.download_instructions', $locale, ['%days%' => '{{ expiration_days }}']),
                $this->slot('download_links'),
            ],
            // The one e-mail this bundle sends to somebody who ordered nothing: whoever a card was bought for. No order link, no lines, no code - what they are owed is the card and the sentence that came with it
            'gift_card_recipient' => [
                $this->text('label.gift_card_recipient_intro', $locale),
                $this->slot('gift_card_message'),
                $this->slot('gift_cards_shared'),
                $this->text('label.gift_card_recipient_how', $locale),
            ],
            'basket_reminder_first' => $this->reminder('label.basket_reminder_first', $locale),
            'basket_reminder_second' => $this->reminder('label.basket_reminder_second', $locale),
        ];
    }

    /**
     * The two reminders differ by their opening sentence alone, the rest saying the same thing at J+1 and J+7.
     *
     * @return list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}>
     */
    private function reminder(string $opening, string $locale): array
    {
        return [
            $this->text($opening, $locale),
            $this->text('label.basket_products_reminder', $locale),
            $this->slot('items'),
            ['button', null, null, null, $this->trans('label.basket_reminder_pay', $locale), '{{ pay_url }}'],
            $this->text('label.basket_reminder_ignore', $locale, ['%days%' => '{{ days }}']),
        ];
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string} */
    private function text(string $key, string $locale, array $parameters = []): array
    {
        return ['text', null, null, $this->trans($key, $locale, $parameters), null, null];
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string} */
    private function slot(string $name): array
    {
        return ['slot', null, null, null, $name, null];
    }

    // A catalogue parameter becomes the "{{ name }}" an EmailTemplate block substitutes: the two placeholder syntaxes have to meet somewhere, and an admin editing that sentence in the back-office sees the one the editor documents
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'payment', $locale);
    }
}
