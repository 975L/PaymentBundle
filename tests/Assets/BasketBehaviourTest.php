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

// assets/js/basket.js run rather than read, over the three blocks a page carries: Intl decides how an amount reads, so the browser's totals are checked against the server's, and each scenario takes a fresh module, the basket being held between instances
#[Group('browser')]
class BasketBehaviourTest extends JsCase
{
    // A basket of two lines: fifty euros of articles, five of shipping, ten of tax held in them
    private const array BASKET = [
        'quantity' => 3,
        'total' => 5000,
        'shipping' => 500,
        'vat' => 1000,
        'currency' => 'eur',
        'contentflags' => 1,
        'items' => [
            'product' => [
                7 => ['quantity' => 2, 'total' => 4000, 'item' => ['currency' => 'eur']],
                9 => ['quantity' => 1, 'total' => 1000, 'item' => ['currency' => 'eur']],
            ],
        ],
    ];

    // A page carries one block per place the basket shows, and they would otherwise each ask for it
    public function testTheWholeDocumentAsksForTheBasketOnce(): void
    {
        $this->assertSame(1, $this->basket('return asked("/shop/basket/json");'), 'Each block showing the basket asked for it on its own, so a page carrying three of them opens with three identical requests.');
    }

    // A basket nobody could load is said so, and asked for again rather than the failure being handed out for the next five seconds
    public function testABasketThatCouldNotBeLoadedIsSaidAndAskedForAgain(): void
    {
        $retried = $this->basket(
            'const said = message().textContent;
             const first = asked("/shop/basket/json");
             answer("/shop/basket/json", { basket: window.__basket });
             root.querySelector("#basket-page").insertAdjacentHTML("afterend", "<div id=\'late\' data-controller=\'basket\'><span data-basket-target=\'total\'></span></div>");
             await settle();

             return { said, first, then: asked("/shop/basket/json"), shown: text(root.querySelector("#late [data-basket-target=total]")) };',
            ['fail' => true]
        );

        $this->assertSame('Impossible de charger le panier.', $retried['said'], 'A basket that could not be loaded says nothing at all, the page simply staying empty.');
        $this->assertSame(1, $retried['first']);
        $this->assertSame(2, $retried['then'], 'The failure was kept and handed out again, so every later block shows an empty basket until the cache runs out.');
        $this->assertSame('55,00 €', $retried['shown'], 'The block that arrived after the failure was handed the failure rather than the basket.');
    }

    // What is charged: the articles, the shipping, less what a code took off - the same rule as Basket::getPayable(), printed beside the total the server rendered
    public function testTheButtonSaysWhatIsAboutToBeCharged(): void
    {
        $this->assertSame('Payer 55,00 €', $this->basket('return text(target("submitButton"), "value");'), 'The button does not name the amount, or names one the server would not charge.');
    }

    // A gift card worth more than the basket pays nothing, and must never print an amount owed the other way round
    public function testACodeWorthMoreThanTheBasketLeavesNothingToPay(): void
    {
        $paid = $this->basket(
            'return { button: text(target("submitButton"), "value"), navbar: text(target("total")) };',
            ['discountAmount' => 9000, 'discountKind' => 'gift_card', 'discountCode' => 'CADEAU']
        );

        $this->assertSame('Payer 0,00 €', $paid['button'], 'A card worth more than the basket printed a negative amount to pay.');
        $this->assertSame('0,00 €', $paid['navbar'], 'The navbar and the button disagree on what is about to be charged.');
    }

    // The amount is written by the browser from the page's own language and the basket's currency, which is what makes it read like the one Twig rendered with format_currency
    public function testAnAmountIsWrittenTheWayThePagesLanguageWritesIt(): void
    {
        $written = $this->basket('return { subtotal: text(target("subtotal")), vat: text(target("vat")), row: text(target("itemTotal")) };');

        $this->assertSame('50,00 €', $written['subtotal'], 'The articles total is not written the way the page\'s language writes an amount.');
        $this->assertSame('10,00 €', $written['vat']);
        $this->assertSame('40,00 €', $written['row'], 'A row of the basket is not written the way the totals under it are.');
    }

    // A line at nothing is a line offered, not a line at zero
    public function testARowCostingNothingIsWrittenAsFreeRatherThanAsZero(): void
    {
        $this->assertSame(
            'Gratuit',
            $this->basket('return text(target("itemTotal"));', ['items' => ['product' => [7 => ['quantity' => 1, 'total' => 0, 'item' => ['currency' => 'eur']]]]]),
            'A row that costs nothing is printed as an amount of zero.'
        );
    }

    // The code line is the only place a customer reads what a code took off, and which of the two kinds it was
    public function testTheCodeLineNamesTheKindOfCodeAndWhatItTookOff(): void
    {
        $card = $this->basket(
            'return { hidden: target("codeRow").hidden, label: text(target("codeLabel")), amount: text(target("codeAmount")) };',
            ['discountAmount' => 1500, 'discountKind' => 'gift_card', 'discountCode' => 'CADEAU']
        );
        $promotion = $this->basket('return text(target("codeLabel"));', ['discountAmount' => 1500, 'discountKind' => 'promotion', 'discountCode' => 'RENTREE']);

        $this->assertFalse($card['hidden'], 'A basket carrying a code shows no line for it, the total simply dropping without a word.');
        $this->assertSame('Carte cadeau CADEAU', $card['label'], 'The line does not say which code was applied, nor that it is a card.');
        $this->assertSame('-15,00 €', $card['amount'], 'What the code took off is not printed as an amount taken off.');
        $this->assertSame('Remise RENTREE', $promotion, 'A promotion is announced as a gift card.');
    }

    public function testABasketWithNoCodeShowsNoLineForOne(): void
    {
        $this->assertTrue((bool) $this->basket('return target("codeRow").hidden;'), 'An empty code line is shown on a basket that carries no code.');
    }

    // The refusal is the server's own sentence, it being the only one that knows whether a code is unknown, expired, out of quota or short of a minimum
    public function testARefusedCodeShowsTheServersOwnSentenceAndIsLeftInTheField(): void
    {
        $refused = $this->basket(
            'answer("/shop/basket/code", { error: "Ce code a expire le 12 aout." });
             target("code").value = "PERIME";
             root.querySelector("#apply").click();
             await settle();

             return { said: message().textContent, left: target("code").value };'
        );

        $this->assertSame('Ce code a expire le 12 aout.', $refused['said'], 'A refused code is answered with a sentence of this bundle\'s own, which cannot say why it was refused.');
        $this->assertSame('PERIME', $refused['left'], 'A refused code was wiped from the field, so the customer can neither see what they typed nor correct it.');
    }

    public function testATakenCodeIsClearedFromTheFieldAndAppliedToTheTotals(): void
    {
        $taken = $this->basket(
            'answer("/shop/basket/code", { basket: { ...window.__basket, discountAmount: 1500, discountKind: "promotion", discountCode: "RENTREE" } });
             target("code").value = "RENTREE";
             root.querySelector("#apply").click();
             await settle();

             return { left: target("code").value, button: text(target("submitButton"), "value"), line: text(target("codeLabel")) };'
        );

        $this->assertSame('', $taken['left'], 'A code already applied is left in the field, where a second press applies it again.');
        $this->assertSame('Payer 40,00 €', $taken['button'], 'The totals were not redrawn from what the server answered after the code.');
        $this->assertSame('Remise RENTREE', $taken['line'], 'The line naming the code was not drawn from the answer that took it.');
    }

    // A code typed and left in the field would otherwise be paid over: the customer never saw the "Apply" button beside it
    public function testACodeLeftInTheFieldIsAppliedOnTheWayToTheCoordinates(): void
    {
        $stopped = $this->basket(
            'answer("/shop/basket/code", { error: "Ce code est inconnu." });
             target("code").value = "INCONNU";
             let followed = true;
             root.querySelector("#pay").addEventListener("click", (event) => { followed = !event.defaultPrevented; });
             root.querySelector("#pay").click();
             await settle();

             return { followed, sent: asked("/shop/basket/code"), said: message().textContent };'
        );

        $this->assertFalse($stopped['followed'], 'The customer was sent to the coordinates with a code still sitting in the field, and paid full price.');
        $this->assertSame(1, $stopped['sent'], 'The code left in the field was never sent.');
        $this->assertSame('Ce code est inconnu.', $stopped['said'], 'A refused code sent the customer on rather than keeping them on the basket, where the refusal is written.');
    }

    public function testAnEmptyFieldLetsTheLinkThrough(): void
    {
        $through = $this->basket(
            'let followed = true;
             root.querySelector("#pay").addEventListener("click", (event) => { followed = !event.defaultPrevented; });
             root.querySelector("#pay").click();
             await settle();

             return { followed, sent: asked("/shop/basket/code") };'
        );

        $this->assertTrue($through['followed'], 'A customer with no code to apply is held back on the basket.');
        $this->assertSame(0, $through['sent'], 'An empty field was sent to the server as a code.');
    }

    // A file is bought once, a second copy of the same one being nothing to sell
    public function testTheAddButtonOfADigitalItemAlreadyInTheBasketIsRefused(): void
    {
        $refused = $this->basket(
            'return { file: root.querySelector("#add-file").disabled, plain: root.querySelector("#add-plain").disabled };',
            ['items' => ['product' => [7 => ['quantity' => 1, 'total' => 1000, 'item' => ['currency' => 'eur', 'file' => 'livre.pdf']], 9 => ['quantity' => 1, 'total' => 1000, 'item' => ['currency' => 'eur']]]]]
        );

        $this->assertTrue($refused['file'], 'A file already in the basket can be added to it a second time.');
        $this->assertFalse($refused['plain'], 'An item that is not a file was refused along with it.');
    }

    // Nothing left to order once what is already ordered plus what the basket holds reaches the limit
    public function testTheAddButtonOfAnItemAtItsLimitIsRefusedAndLooksIt(): void
    {
        $capped = $this->basket('return { disabled: root.querySelector("#add-limited").disabled, dimmed: root.querySelector("#add-limited").classList.contains("disabled") };');

        $this->assertTrue($capped['disabled'], 'An item already ordered up to its limit can be ordered again.');
        $this->assertTrue($capped['dimmed'], 'A refused button looks exactly like one that can still be pressed.');
    }

    // The add buttons are read on the whole document, a product sheet carrying them and no basket table at all
    public function testTheAddButtonsOfAPageWithNoBasketTableAreReadToo(): void
    {
        $this->assertTrue(
            (bool) $this->basket(
                'root.querySelector("#basket-page").remove();
                 root.querySelector("#late")?.remove();

                 return root.querySelector("#add-file").disabled;',
                ['items' => ['product' => [7 => ['quantity' => 1, 'total' => 1000, 'item' => ['currency' => 'eur', 'file' => 'livre.pdf']]]]]
            ),
            'A product sheet showing no basket leaves its buttons clickable for a file already bought.'
        );
    }

    // The basket page is not the only thing showing the basket, and the button that emptied it is elsewhere
    public function testEmptyingTheBasketPutsTheEmptyPageUpAndTakesTheNavbarDown(): void
    {
        $emptied = $this->basket(
            'return { page: root.querySelector("#basket-page").textContent.trim(), navbar: root.querySelector("#basket-navbar").classList.contains("d-none"), body: document.body.classList.contains("has-basket-navbar") };',
            ['quantity' => 0, 'total' => 0, 'items' => []]
        );

        $this->assertSame('Votre panier est vide', $emptied['page'], 'An emptied basket leaves its rows on the page.');
        $this->assertTrue($emptied['navbar'], 'The basket navbar stays up over a basket holding nothing.');
        $this->assertFalse($emptied['body'], 'The page is still laid out for a navbar that is no longer shown.');
    }

    public function testABasketHoldingSomethingRaisesItsNavbar(): void
    {
        $raised = $this->basket('return { navbar: root.querySelector("#basket-navbar").classList.contains("d-none"), body: document.body.classList.contains("has-basket-navbar"), count: target("quantity").textContent };');

        $this->assertFalse($raised['navbar'], 'The navbar stays down over a basket that holds something.');
        $this->assertTrue($raised['body'], 'The page is not laid out for the navbar it has just raised.');
        $this->assertSame('3', $raised['count'], 'The navbar does not say how much the basket holds.');
    }

    // A row whose item the basket no longer holds is faded out and taken away
    public function testARowTheBasketNoLongerHoldsLeavesThePage(): void
    {
        $left = $this->basket(
            'const fading = root.querySelector("#item-product-9").classList.contains("fade-out");
             await new Promise((r) => setTimeout(r, 150));

             return { fading, gone: !root.querySelector("#item-product-9"), kept: !!root.querySelector("#item-product-7") };',
            ['items' => ['product' => [7 => ['quantity' => 2, 'total' => 4000, 'item' => ['currency' => 'eur']]]]]
        );

        $this->assertTrue($left['fading'], 'A row on its way out is taken away without a word.');
        $this->assertTrue($left['gone'], 'A row whose item the basket no longer holds stays on the page.');
        $this->assertTrue($left['kept'], 'The rows the basket still holds were taken away with it.');
    }

    // The line the basket page raises an order with, compared against what the basket holds rather than against what is paid - the way the server applies the shipping
    public function testTheFreeShippingLineSaysWhatIsLeftToReachIt(): void
    {
        $missing = $this->basket('return text(target("freeShipping"));');
        $reached = $this->basket('return { said: text(target("freeShipping")), hidden: target("freeShipping").hidden };', ['total' => 12000]);
        $nothing = $this->basket('return target("freeShipping").hidden;', ['contentflags' => 0]);

        $this->assertSame('Plus que 50,00 € pour bénéficier de la livraison offerte', $missing, 'The line does not say what is left to reach the free shipping.');
        $this->assertSame('La livraison de cette commande est offerte', $reached['said'], 'A basket over the threshold is still told what is left to reach it.');
        $this->assertFalse($reached['hidden']);
        $this->assertTrue((bool) $nothing, 'A basket holding nothing to ship is told about a free shipping it cannot reach.');
    }

    public function testShippingAtNothingIsWrittenAsOfferedRatherThanAsZero(): void
    {
        $this->assertSame('Offert', $this->basket('return text(target("shipping"));', ['shipping' => 0]), 'A shipping that costs nothing is printed as an amount of zero.');
    }

    // One line whatever the rates, and no line at all where there is no tax to hold
    public function testTheTaxLineIsShownOnlyWhereThereIsTax(): void
    {
        $this->assertTrue((bool) $this->basket('return target("vatRow").hidden;', ['vat' => 0]), 'A tax line reading zero is shown on a basket that holds no tax.');
        $this->assertFalse((bool) $this->basket('return target("vatRow").hidden;'), 'The tax line is hidden on a basket that does hold tax.');
    }

    // The blocks of a page each hold part of the basket, and the one that changed has to hand the others what it got
    public function testTheBlockThatChangedTheBasketTellsTheOthers(): void
    {
        $told = $this->basket(
            'answer("/shop/basket", { basket: { ...window.__basket, quantity: 9, total: 9000 } });
             const asking = asked("/shop/basket/json");
             root.querySelector("#add-plain").click();
             await settle();

             return { navbar: target("quantity").textContent, total: text(target("total")), reloaded: asked("/shop/basket/json") - asking, said: message().textContent };'
        );

        $this->assertSame('9', $told['navbar'], 'A block that changed the basket left the navbar of the same page saying something else.');
        $this->assertSame('95,00 €', $told['total'], 'The navbar was told the count and not the amount.');
        $this->assertSame(0, $told['reloaded'], 'The other blocks asked the server for the basket all over again, where the one that changed it had just been handed it.');
        $this->assertSame('"La tasse" a ete ajoutee', $told['said'], 'Nothing was said about what was just added.');
    }

    // Turbo caches the page and connects the controller again on the way back, so a listener left behind piles up one copy per visit
    public function testTheDocumentListenerDoesNotOutliveTheBlock(): void
    {
        $this->assertSame(
            '3',
            $this->basket(
                'const shown = target("quantity");
                 document.createElement("div").appendChild(root.querySelector("#basket-navbar"));
                 await settle();
                 document.dispatchEvent(new CustomEvent("basket:update", { detail: { data: { basket: { ...window.__basket, quantity: 42 } } } }));
                 await settle();

                 return shown.textContent;'
            ),
            'A block taken off the page goes on redrawing itself, one copy of the listener per visit.'
        );
    }

    private function basket(string $probe, array $basket = []): mixed
    {
        // Intl writes a narrow no-break space before the symbol, which is right on the page and unreadable in a failure message
        $preamble = 'const settle = () => new Promise((r) => setTimeout(r, 20));
             const text = (el, from) => (from ? el[from] : el.textContent).replace(/[\u00a0\u202f\u2009]/g, " ");
             const message = () => root.querySelector(".global-message");
             const target = (name) => root.querySelector("[data-basket-target=" + name + "]");
             const asked = (path) => window.__requests.filter((request) => request.url === path).length;
             const answer = (path, body) => { window.__answers[path] = { body }; }; ';

        return $this->observe(
            $this->page(),
            ['basket' => 'basket'],
            $preamble . $probe,
            [
                // The module holds the page's basket between its instances, so a scenario is given a copy of its own rather than whatever the previous one left in it
                'fresh' => true,
                'before' => $this->answers($basket),
                'settle' => 60,
            ]
        );
    }

    private function answers(array $basket): string
    {
        $failing = $basket['fail'] ?? false;
        unset($basket['fail']);

        return sprintf(
            // Written by the browser from the page's language: the amounts asserted above read as the ones Twig rendered only under a page that declares one
            'document.documentElement.lang = "fr";
             window.__requests = [];
             window.__basket = %s;
             window.__answers = { "/shop/basket/json": %s };
             window.fetch = (url, options) => {
                 const path = new URL(url, window.location.origin).pathname;
                 window.__requests.push({ url: path, method: options?.method ?? "GET", body: options?.body ?? null });
                 const answer = window.__answers[path];

                 if (undefined === answer) {
                     return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
                 }

                 return answer.fail
                     ? Promise.reject(new Error("offline"))
                     : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(answer.body) });
             };',
            json_encode(array_replace(self::BASKET, $basket), \JSON_THROW_ON_ERROR),
            $failing ? '{ fail: true }' : '{ body: { basket: window.__basket } }'
        );
    }

    // The three blocks a real page carries: the navbar, the basket page, and a product sheet whose add buttons belong to no basket table at all
    private function page(): string
    {
        return '<div class="global-message"></div>
            <div id="basket-navbar" class="d-none" data-controller="basket">
                <span data-basket-target="quantity"></span>
                <span data-basket-target="total"></span>
            </div>
            <div id="basket-page" data-controller="basket">
                <table><tbody>
                    <tr id="item-product-7" data-type="product" data-item-id="7">
                        <td data-basket-target="itemQuantity" data-item-id="product-7"></td>
                        <td data-basket-target="itemTotal" data-item-id="product-7"></td>
                    </tr>
                    <tr id="item-product-9" data-type="product" data-item-id="9">
                        <td data-basket-target="itemQuantity" data-item-id="product-9"></td>
                        <td data-basket-target="itemTotal" data-item-id="product-9"></td>
                    </tr>
                    <tr data-basket-target="vatRow"><td data-basket-target="vat"></td></tr>
                </tbody></table>
                <span data-basket-target="subtotal"></span>
                <span data-basket-target="shipping"></span>
                <p data-basket-target="freeShipping" data-shipping-free="10000" data-shipping-flag="1"></p>
                <div data-basket-target="codeRow" hidden><span data-basket-target="codeLabel"></span><span data-basket-target="codeAmount"></span></div>
                <input type="text" data-basket-target="code" value="">
                <button id="apply" type="button" data-action="click->basket#applyCode">Appliquer</button>
                <a id="pay" href="#coordonnees" data-action="click->basket#validateWithCode">Valider</a>
                <input type="submit" data-basket-target="submitButton" value="">
            </div>
            <template id="empty-basket-template"><p class="empty">Votre panier est vide</p></template>
            <div id="product-sheet" data-controller="basket">
                <button id="add-file" data-action="click->basket#addItem" data-type="product" data-item-id="7" data-limited="0" data-ordered="0" data-quantity="1" data-title="Le livre" data-text="a ete ajoute" data-alert="success">Ajouter</button>
                <button id="add-plain" data-action="click->basket#addItem" data-type="product" data-item-id="9" data-limited="0" data-ordered="0" data-quantity="1" data-title="La tasse" data-text="a ete ajoutee" data-alert="success">Ajouter</button>
                <button id="add-limited" data-action="click->basket#addItem" data-type="product" data-item-id="7" data-limited="3" data-ordered="1" data-quantity="1" data-title="Le livre" data-text="a ete ajoute" data-alert="success">Ajouter</button>
            </div>';
    }
}
