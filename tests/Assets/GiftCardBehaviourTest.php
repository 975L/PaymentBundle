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

// assets/js/gift-card.js run over the card GiftCard/Card.html.twig draws: the code is fetched rather than rendered because a forwarded link is read by robots that run no script, so a failed request must leave the panel rubbable rather than a card carrying neither code nor panel
#[Group('browser')]
class GiftCardBehaviourTest extends JsCase
{
    private const string URL = '/shop/gift-card/ABC123/code';

    public function testRubbingThePanelOffPutsTheCodeOnTheCard(): void
    {
        $shown = $this->card(
            'scratch().click();
             await settle();

             return { code: code().textContent, hidden: code().hidden, scratched: card().classList.contains("gift-card-scratched"), asked: window.__requests };'
        );

        $this->assertSame('CADEAU-4242', $shown['code'], 'The code the server answered never reached the card.');
        $this->assertFalse($shown['hidden'], 'The code was written where nobody can read it.');
        $this->assertTrue($shown['scratched'], 'The card does not say it has been rubbed, so the panel goes on covering the code.');
        $this->assertSame([self::URL], $shown['asked'], 'The code was not asked for at the address the card carries.');
    }

    // A second press asks for nothing: the code is already on the card
    public function testASecondPressAsksForNothing(): void
    {
        $this->assertSame(
            1,
            $this->card('scratch().click(); await settle(); scratch().click(); await settle(); return window.__requests.length;'),
            'Every press asks the server for a code that is already on the card.'
        );
    }

    // What failed is a request, and the panel is what lets the reader try again
    public function testACardWhoseRequestFailedKeepsItsPanelAndCanBeRubbedAgain(): void
    {
        $failed = $this->card(
            'window.__answer = { fail: true };
             scratch().click();
             await settle();
             const said = scratch().textContent;
             window.__answer = { code: "CADEAU-4242" };
             scratch().click();
             await settle();

             return { said, scratched: card().classList.contains("gift-card-scratched"), code: code().textContent, asked: window.__requests.length };'
        );

        $this->assertSame('Impossible d\'afficher ce code. Merci de réessayer.', $failed['said'], 'A card whose code could not be fetched says nothing, and the reader is left pressing a panel that answers nothing.');
        $this->assertSame('CADEAU-4242', $failed['code'], 'A card whose request failed can never be rubbed again, so the code is lost for good.');
        $this->assertTrue($failed['scratched']);
        $this->assertSame(2, $failed['asked'], 'The second press asked for nothing, the card holding itself already rubbed.');
    }

    // An answer that arrives without a code is a failure whatever its status: a card showing an empty code is a card with nothing on it
    public function testAnAnswerCarryingNoCodeIsTreatedAsAFailure(): void
    {
        $empty = $this->card(
            'window.__answer = { error: "Cette carte a deja ete utilisee." };
             scratch().click();
             await settle();

             return { said: scratch().textContent, code: code().textContent, hidden: code().hidden };'
        );

        $this->assertSame('Impossible d\'afficher ce code. Merci de réessayer.', $empty['said'], 'An answer with no code was written onto the card as though it were one.');
        $this->assertSame('', $empty['code']);
        $this->assertTrue($empty['hidden'], 'An empty code was uncovered on the card.');
    }

    // The reason the code is fetched at all: a card's address is meant to be forwarded, and a robot reading the markup of a link pasted into a chat must find nothing on it
    public function testTheCodeIsOnTheCardNowhereBeforeItIsAskedFor(): void
    {
        $untouched = $this->card('return { asked: window.__requests.length, code: code().textContent, hidden: code().hidden, markup: card().innerHTML.includes("CADEAU") };');

        $this->assertSame(0, $untouched['asked'], 'The card asks for its code as soon as it is drawn, so a preview fetching the page gets it too.');
        $this->assertSame('', $untouched['code'], 'The code is on the card before anybody asked to see it.');
        $this->assertTrue($untouched['hidden']);
        $this->assertFalse($untouched['markup'], 'The code sits in the markup, which is all a robot ever reads.');
    }

    private function card(string $probe): mixed
    {
        $preamble = 'const settle = () => new Promise((r) => setTimeout(r, 20));
             const card = () => root.querySelector("[data-controller]");
             const scratch = () => root.querySelector("[data-giftCard-target=scratch]");
             const code = () => root.querySelector("[data-giftCard-target=code]"); ';

        return $this->observe(
            sprintf(
                '<div class="gift-card" data-controller="giftCard" data-giftCard-url-value="%s">
                    <button type="button" class="gift-card-scratch" data-action="giftCard#reveal" data-giftCard-target="scratch">Gratter</button>
                    <span class="gift-card-code" data-giftCard-target="code" hidden></span>
                </div>',
                self::URL
            ),
            ['giftCard' => 'gift-card'],
            $preamble . $probe,
            [
                // The reveal route answered by the scenario, which is also what keeps this test off the network
                'before' => 'document.documentElement.lang = "fr";
                    window.__requests = [];
                    window.__answer = { code: "CADEAU-4242" };
                    window.fetch = (url) => {
                        window.__requests.push(url);

                        return window.__answer.fail
                            ? Promise.reject(new Error("offline"))
                            : Promise.resolve({ ok: true, json: () => Promise.resolve(window.__answer) });
                    };',
            ]
        );
    }
}
