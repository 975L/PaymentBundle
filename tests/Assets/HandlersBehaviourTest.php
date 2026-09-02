<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\Group;

// assets/js/handlers.js run rather than read: "getLanguage" and "translate" are UiBundle's own, working on this bundle's catalogue only because both read "this.translations" - a borrowing that breaks silently, so it is asked for a word only this catalogue holds
#[Group('browser')]
class HandlersBehaviourTest extends JsCase
{
    // The borrowed methods read the catalogue of whoever they were hung on, which is this bundle's
    public function testTheBorrowedTranslationReadsThisBundlesOwnCatalogue(): void
    {
        $said = $this->handlers('return { fr: h.translate("basket.discount"), en: (document.documentElement.lang = "en", h.translate("basket.discount")) };');

        $this->assertSame('Remise', $said['fr'], 'The word came back untranslated, so the borrowed method is reading UiBundle\'s catalogue rather than the one it was handed.');
        $this->assertSame('Discount', $said['en']);
    }

    // The page says what language it is in, and a shop served in one language must not be written to in another
    public function testTheLanguageIsTheOneThePageDeclares(): void
    {
        $read = $this->handlers(
            'const fromHtml = h.getLanguage();
             document.documentElement.removeAttribute("lang");
             document.body.setAttribute("data-language", "es-ES");
             const fromBody = h.getLanguage();
             document.body.removeAttribute("data-language");
             document.documentElement.lang = "fr";

             return { fromHtml, fromBody };'
        );

        $this->assertSame('fr', $read['fromHtml'], 'The language the page declares is not the one an amount and a sentence are written in.');
        $this->assertSame('es', $read['fromBody'], 'A page declaring its language on the body alone is not read at all.');
    }

    // A key nobody translated is answered with itself rather than with nothing: a missing word shows up as a word out of place, never as an empty line
    public function testAWordNobodyTranslatedComesBackAsItself(): void
    {
        $this->assertSame('basket.inconnu', $this->handlers('return h.translate("basket.inconnu");'), 'An untranslated key comes back empty, so the message it was meant for reads as a blank.');
    }

    // A language this bundle ships no catalogue for falls back on English rather than on the keys
    public function testALanguageWithNoCatalogueFallsBackOnEnglish(): void
    {
        $this->assertSame(
            'Discount',
            $this->handlers('document.documentElement.lang = "de"; const said = h.translate("basket.discount"); document.documentElement.lang = "fr"; return said;'),
            'A page in a language this bundle ships no catalogue for reads the raw keys.'
        );
    }

    // The amounts the basket rewrites have to read exactly like the ones Twig rendered with format_currency
    public function testAnAmountIsWrittenWithTheSeparatorAndTheSymbolOfThePagesLanguage(): void
    {
        $written = $this->handlers(
            'const plain = (value) => value.replace(/[\u00a0\u202f\u2009]/g, " ");
             const fr = plain(h.formatAmount(123456, "eur"));
             document.documentElement.lang = "en";
             const en = plain(h.formatAmount(123456, "usd"));
             document.documentElement.lang = "fr";

             return { fr, en, none: h.formatAmount(123456, null) };'
        );

        $this->assertSame('1 234,56 €', $written['fr'], 'An amount is not written the way the page\'s language writes one.');
        $this->assertSame('$1,234.56', $written['en'], 'The symbol is put where the page\'s language does not put it.');
        $this->assertSame('1234.56', $written['none'], 'An amount with no currency to name is not written as a plain number.');
    }

    // The placeholder the basket draws is one element, and a message left over from a previous answer would read as the answer to this one
    public function testAMessageIsShownWhereTheBasketDrawsItsPlaceholder(): void
    {
        $shown = $this->handlers(
            'h.displayMessage("Ajoute au panier", "alert-success");
             const first = { text: message().textContent, className: message().className, shown: message().style.display };
             h.displayMessage("Impossible de charger le panier.", "alert-danger");

             return { first, second: { text: message().textContent, className: message().className } };'
        );

        $this->assertSame('Ajoute au panier', $shown['first']['text']);
        $this->assertSame('global-message alert alert-success', $shown['first']['className'], 'The message does not carry the kind of alert it is, so a failure reads like a confirmation.');
        $this->assertSame('block', $shown['first']['shown'], 'The placeholder was written into and left hidden.');
        $this->assertSame('Impossible de charger le panier.', $shown['second']['text'], 'A second message is added beside the first rather than replacing it.');
        $this->assertSame('global-message alert alert-danger', $shown['second']['className'], 'The kind of the previous message was kept, so a failure is announced in green.');
    }

    // The placeholder is drawn by the basket page alone, and a product sheet has none
    public function testAPageWithNoPlaceholderIsSimplyLeftAlone(): void
    {
        $this->assertTrue(
            (bool) $this->handlers('message().remove(); h.displayMessage("Ajoute au panier", "alert-success"); return true;'),
            'A page with nowhere to show a message takes itself down instead.'
        );
    }

    // The timezone the session is stored with, sent once and read from the browser itself
    public function testTheTimezoneSentIsTheBrowsersOwn(): void
    {
        $sent = $this->handlers(
            'const answered = h.sendTimezoneToServer();

             return { answered, url: window.__requests[0]?.url, body: window.__requests[0]?.body };'
        );

        $this->assertSame('/set-timezone', $sent['url'], 'The timezone is not sent where the session reads it from.');
        $this->assertSame(sprintf('{"timezone":"%s"}', $sent['answered']), $sent['body'], 'What is sent is not the timezone the browser answered.');
        $this->assertNotSame('', $sent['answered'], 'The browser was never asked what timezone it is in.');
    }

    private function handlers(string $probe): mixed
    {
        return $this->observe(
            '<div class="global-message"></div>',
            [],
            'const h = mod.handlers.default;
             const message = () => root.querySelector(".global-message"); ' . $probe,
            [
                'modules' => ['handlers' => 'handlers'],
                'before' => 'document.documentElement.lang = "fr";
                    window.__requests = [];
                    window.fetch = (url, options) => {
                        window.__requests.push({ url, body: options?.body ?? null });

                        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
                    };',
            ]
        );
    }
}
