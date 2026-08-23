<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// The card is drawn by three files no browser here runs - a template, a stylesheet and a Stimulus controller - and what holds them together is checked against the contract the other two assume. The one property worth guarding above the rest: a card whose code is fetched must not carry that code in its markup
class GiftCardMarkupTest extends TestCase
{
    private const string COMPONENT = 'templates/components/GiftCard/Card.html.twig';
    private const string CONTROLLER_JS = 'assets/js/gift-card.js';
    private const string BARREL = 'assets/controllers.js';
    private const string STYLESHEET = 'sass/_gift-card.scss';

    // The panel hides a request and not a hidden element: a page pasted into a chat is fetched by a robot that reads the markup and runs no script, and a code sitting in that markup is a balance read off an unfurl
    public function testThePanelIsOnlyDrawnWhereTheCodeIsFetchedRatherThanWritten(): void
    {
        $component = $this->read(self::COMPONENT);

        $this->assertStringContainsString('{% set hasScratch = scratch and revealUrl and not code %}', $component);
        $this->assertStringContainsString('<span class="gift-card-code" data-giftCard-target="code" hidden></span>', $component, 'The panel writes the code into the page instead of leaving it to be asked for.');
    }

    // Basket:Items is rendered read-only on the two pages a shared order is settled from, which the payer reads and the customer does not: the code that paid for it is printed on the basket its holder edits and nowhere else
    public function testTheBasketPrintsTheCodeOnlyWhereItIsTheReadersOwn(): void
    {
        $component = $this->read('templates/components/Basket/Items.html.twig');

        $this->assertStringContainsString('{% if editable %} {{ basket.discountCode }}{% endif %}', $component);
        $this->assertStringNotContainsString('|trans }} {{ basket.discountCode }}', $component, 'The code is printed whatever the page, the payer of a shared order included.');
    }

    /**
     * The email sent to whoever a card was bought for shows an amount and an address, never a code.
     *
     * The same reason the panel exists at all, one step earlier: that message travels through a mailbox that is
     * not the buyer's, and a code printed in it is a balance anybody forwarding the mail can spend.
     */
    public function testTheEmailToTheRecipientCarriesNoCode(): void
    {
        $component = $this->read('templates/components/Basket/GiftCardsShared.html.twig');

        $this->assertStringNotContainsString('giftCard.code', $component);
        $this->assertStringContainsString("url('gift_card_display'", $component);
    }

    // A card sold without a panel must not get one back: a prop holding false comes down as the empty string it was rendered as, which "|default(true)" reads as absent
    public function testThePanelIsReadWithoutTheDefaultFilterThatWouldTurnFalseBackIntoTrue(): void
    {
        $component = $this->read(self::COMPONENT);

        $this->assertStringContainsString('{% set scratch = scratch is defined ? scratch|to_bool : true %}', $component);
        $this->assertStringNotContainsString('scratch|default(true)', $component);
    }

    // The shape of a card held in the hand, which is UiBundle's own (see its FlipCardType::RATIO_CHOICES) - and the fold, the toggles and the inert face that come with it
    public function testTheCardIsBuiltOnTheFlipCardInItsCreditCardShape(): void
    {
        $component = $this->read(self::COMPONENT);

        $this->assertStringContainsString('class="gift-card flip-card flip-card-ratio-credit-card"', $component);
        $this->assertStringContainsString('data-flipCard-target="face"', $component);
        $this->assertStringContainsString('data-action="flipCard#toggle"', $component);
    }

    // Registered lazily like the basket's, the layout loading this barrel site-wide while a card is on a handful of pages
    public function testTheScratchControllerIsRegisteredUnderTheNameTheTemplateWrites(): void
    {
        $this->assertStringContainsString("giftCard: () => import('./js/gift-card.js'),", $this->read(self::BARREL));
        $this->assertStringContainsString('data-controller="flipCard{% if revealUrl %} giftCard{% endif %}"', $this->read(self::COMPONENT));
    }

    // Each end of the pair: what the template declares is what the controller reads
    public function testTheControllerReadsTheTargetsAndTheAddressTheTemplateDeclares(): void
    {
        $controller = $this->read(self::CONTROLLER_JS);
        $component = $this->read(self::COMPONENT);

        $this->assertStringContainsString('static targets = ["code", "scratch"];', $controller);
        $this->assertStringContainsString('static values = { url: String };', $controller);
        $this->assertStringContainsString('data-giftCard-url-value=', $component);
        $this->assertStringContainsString('data-giftCard-target="scratch"', $component);
        $this->assertStringContainsString('data-giftCard-target="code"', $component);
    }

    // A class and not a style attribute, the sites running this bundle serving a CSP with no unsafe-inline
    public function testThePanelIsRubbedOffThroughAClassTheStylesheetDeclares(): void
    {
        $this->assertStringContainsString('classList.add("gift-card-scratched")', $this->read(self::CONTROLLER_JS));
        $this->assertStringContainsString('.gift-card-scratched .gift-card-scratch {', $this->read(self::STYLESHEET));
    }

    // The verso is the recto seen from behind, mirrored by the stylesheet: an admin asked for a second file would sooner or later attach an unrelated one
    public function testTheBackVisualIsTheFrontOneTurnedOverRatherThanASecondUpload(): void
    {
        $this->assertStringContainsString('class="gift-card-visual gift-card-visual-back"', $this->read(self::COMPONENT));
        $this->assertStringContainsString('transform: scaleX(-1);', $this->read(self::STYLESHEET));
    }

    private function read(string $path): string
    {
        $file = \dirname(__DIR__, 2) . '/' . $path;

        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }
}
