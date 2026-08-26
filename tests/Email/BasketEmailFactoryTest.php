<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Email;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Email\BasketEmailFactory;
use c975L\PaymentBundle\Entity\Basket;
use c975L\UiBundle\Model\EmailAttachment;
use c975L\UiBundle\Service\EmailTemplateRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// The envelope every basket email shares, now that no class of this bundle addresses a message by hand
class BasketEmailFactoryTest extends TestCase
{
    public function testItAddressesTheBuyerFromTheShopKeys(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertSame('buyer@example.test', $request->to);
        $this->assertSame('orders@shop.test', $request->from);
        $this->assertSame('Shop name', $request->fromName);
        $this->assertSame('contact@shop.test', $request->replyTo);
        $this->assertSame('archive@shop.test', $request->bcc);
    }

    // The composed body is a whole document, EmailTemplateRenderer having wrapped it in the site's layout already - asking UiBundle to wrap it a second time would nest one layout inside the other
    public function testItHandsOverTheComposedBodyAndNoTwigPath(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertSame('<html>composed</html>', $request->html);
        $this->assertNull($request->template);
        $this->assertFalse($request->wrapLayout);
        $this->assertSame([], $request->context);
    }

    // This bundle ships no Twig body any more, so a name neither stored nor declared has nothing to fall back on - and an order confirmed by a blank email is worse than one that fails loudly
    public function testAnEmailWithNoBodyAtAllIsRefusedRatherThanSentEmpty(): void
    {
        $configService = $this->configService([]);
        $emailTemplateRenderer = $this->createStub(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn(null);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('confirm_order');

        new BasketEmailFactory($configService, $emailTemplateRenderer, $this->translator(), $twig)->create($this->basket(), 'label.confirm_order', 'confirm_order');
    }

    public function testItStatesTheShopTheSubjectAndTheOrderNumber(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.items_shipped', 'items_shipped');

        $this->assertSame('Shop My shop - label.items_shipped - ORDER-1', $request->subject);
    }

    // What the body needs reaches it through the renderer's variables, not through a Twig context: the request carries a finished document
    public function testTheContextIsEmptyTheBodyBeingAlreadyRendered(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.download_information', 'download_information', [
            'downloadLinks' => ['a-link'],
            'expirationDays' => 7,
        ]);

        $this->assertSame([], $request->context);
        $this->assertNotNull($request->html);
    }

    // A blank key must come back as null, so UiBundle falls back on the site-wide address instead of building a broken one - a space alone being blank too, which an Address would otherwise throw on
    public function testABlankAddressKeyIsLeftToTheSiteWideFallback(): void
    {
        $configService = $this->configService([
            ['shop-name', 'My shop'],
            ['shop-email-from', ''],
            ['shop-email-from-name', ''],
            ['shop-email-reply-to', ''],
            ['shop-email-reply-to-name', ''],
            ['shop-email-bcc', ' '],
        ]);

        $emailTemplateRenderer = $this->createStub(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn('<html>composed</html>');
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('');

        $request = new BasketEmailFactory($configService, $emailTemplateRenderer, $this->translator(), $twig)->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertNull($request->from);
        $this->assertNull($request->replyTo);
        $this->assertNull($request->bcc);
    }

    // The documents that email's row says it travels with - the invoice and the terms of sale a shop attaches to its confirmations, ticked in the builder one template at a time
    public function testItCarriesWhatItsTemplateSaysItTravelsWithOnceTheShopSendsDocuments(): void
    {
        $attachment = new EmailAttachment('conditions-generales-de-vente.pdf', '%PDF-1.7');

        $request = $this->factory($this->rendererCarrying($attachment))->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertSame([$attachment], $request->attachments);
    }

    // The shop's own switch, off by default: a site whose invoice mentions or terms of sale are not written yet does not attach them to its first order, whatever its templates have ticked
    public function testItSendsNoDocumentAtAllWhileTheShopSwitchIsOff(): void
    {
        $renderer = $this->rendererCarrying(new EmailAttachment('conditions-generales-de-vente.pdf', '%PDF-1.7'));

        $request = $this->factory($renderer, 'false')->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertSame([], $request->attachments);
    }

    // Nothing is even drawn while the switch is off - the invoice PDF is rendered on demand, and rendering one to throw it away is the cost this guards against
    public function testTheDocumentsAreNotEvenDrawnWhileTheSwitchIsOff(): void
    {
        $emailTemplateRenderer = $this->createMock(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn('<html>composed</html>');
        $emailTemplateRenderer->expects($this->never())->method('attachmentsFor');

        $this->factory($emailTemplateRenderer, 'false')->create($this->basket(), 'label.confirm_order', 'confirm_order');
    }

    // The order and the language it was placed in, so a document is drawn about that sale and written to that customer
    public function testTheOrderAndItsLanguageReachWhoeverDrawsTheDocuments(): void
    {
        $basket = $this->basket()->setLocale('de');

        $emailTemplateRenderer = $this->createMock(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn('<html>composed</html>');
        $emailTemplateRenderer->expects($this->once())
            ->method('attachmentsFor')
            ->with('confirm_order', ['basket' => $basket, 'downloadLinks' => ['a-link']], 'de')
            ->willReturn([]);

        $this->factory($emailTemplateRenderer)->create($basket, 'label.confirm_order', 'confirm_order', ['downloadLinks' => ['a-link']]);
    }

    /**
     * A config stub answering that map, and reading a boolean exactly as ConfigService does - the switch is stored
     * as the string "true" or "false", which a plain cast would both read as true.
     *
     * @param list<array{0: string, 1: mixed}> $map
     */
    private function configService(array $map): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap($map);
        $configService->method('getBool')->willReturnCallback(static fn ($value): bool => filter_var($value, \FILTER_VALIDATE_BOOLEAN));

        return $configService;
    }

    private function rendererCarrying(EmailAttachment $attachment): EmailTemplateRenderer
    {
        $emailTemplateRenderer = $this->createStub(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn('<html>composed</html>');
        $emailTemplateRenderer->method('attachmentsFor')->willReturn([$attachment]);

        return $emailTemplateRenderer;
    }

    private function factory(?EmailTemplateRenderer $emailTemplateRenderer = null, string $attachments = 'true'): BasketEmailFactory
    {
        $configService = $this->configService([
            ['shop-name', 'My shop'],
            ['shop-email-from', 'orders@shop.test'],
            ['shop-email-from-name', 'Shop name'],
            ['shop-email-reply-to', 'contact@shop.test'],
            ['shop-email-reply-to-name', 'Shop contact'],
            ['shop-email-bcc', 'archive@shop.test'],
            ['payment-email-attachments', $attachments],
        ]);

        // Where the body comes from - the site's own row or the wording this bundle declares - is EmailTemplateRenderer's affair; these tests are about the envelope around it
        if (null === $emailTemplateRenderer) {
            $emailTemplateRenderer = $this->createStub(EmailTemplateRenderer::class);
            $emailTemplateRenderer->method('renderNamed')->willReturn('<html>composed</html>');
        }

        // Renders each slot wrapper to an empty string: what a slot holds is UiBundle's business, not this factory's envelope
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('');

        return new BasketEmailFactory($configService, $emailTemplateRenderer, $this->translator(), $twig);
    }

    // Returns the key itself, so a subject assertion reads which key was asked for
    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'label.shop' === $id ? 'Shop' : $id);

        return $translator;
    }

    private function basket(): Basket
    {
        $basket = new Basket();
        $basket->setNumber('ORDER-1');
        $basket->setEmail('buyer@example.test');

        return $basket;
    }
}
