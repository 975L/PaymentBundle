<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Email;

use c975L\PaymentBundle\Email\BasketEmailFactory;
use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Entity\Basket;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

/**
 * Writing to the customer in the customer's language.
 *
 * Nothing else knows it: a reminder goes out from a nightly command with no request at all, and a shipping notice
 * from the shopkeeper's own click, in the shopkeeper's own language. The order is the only thing that remembers.
 */
class BasketEmailSenderTest extends TestCase
{
    private ?string $localeWhileBuilding = null;

    // The whole build and send happens in the customer's language - the subject and the fragments are translated as they are built, and there is no later moment to do it in
    public function testTheEmailIsBuiltInTheLanguageTheOrderWasPlacedIn(): void
    {
        $translator = new Translator('fr');

        $this->sender($translator)->send(new Basket()->setLocale('de'), 'label.x', 'confirm_order');

        $this->assertSame('de', $this->localeWhileBuilding);
    }

    // Put back afterwards, or a French order following a German one would go out in German
    public function testTheTranslatorIsPutBackWhereItWas(): void
    {
        $translator = new Translator('fr');

        $this->sender($translator)->send(new Basket()->setLocale('de'), 'label.x', 'confirm_order');

        $this->assertSame('fr', $translator->getLocale());
    }

    // An order taken before the language was kept is written in the site's own, not in nothing at all
    public function testAnOrderWithoutALanguageLeavesTheTranslatorAlone(): void
    {
        $translator = new Translator('fr');

        $this->sender($translator)->send(new Basket(), 'label.x', 'confirm_order');

        $this->assertSame('fr', $this->localeWhileBuilding);
        $this->assertSame('fr', $translator->getLocale());
    }

    private function sender(Translator $translator): BasketEmailSender
    {
        $factory = $this->createStub(BasketEmailFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($translator): EmailSendRequest {
            $this->localeWhileBuilding = $translator->getLocale();

            return new EmailSendRequest('subject', []);
        });

        $emailService = $this->createStub(EmailService::class);
        $emailService->method('send')->willReturn(true);

        return new BasketEmailSender($factory, $emailService, $translator);
    }
}
