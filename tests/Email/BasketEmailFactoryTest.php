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
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    // The body is rendered alone and wrapped by the registry, so the template must never be given a layout of its own
    public function testItAsksForTheLayoutToBeWrappedAroundTheBody(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertTrue($request->wrapLayout);
        $this->assertSame('@c975LPayment/emails/confirm_order.html.twig', $request->template);
        $this->assertNull($request->html);
    }

    public function testItStatesTheShopTheSubjectAndTheOrderNumber(): void
    {
        $request = $this->factory()->create($this->basket(), 'label.items_shipped', 'items_shipped');

        $this->assertSame('Shop My shop - label.items_shipped - ORDER-1', $request->subject);
    }

    // The basket is always there, whatever else the body asks for
    public function testTheContextCarriesTheBasketAlongsideWhatTheBodyNeeds(): void
    {
        $basket = $this->basket();
        $request = $this->factory()->create($basket, 'label.download_information', 'download_information', [
            'downloadLinks' => ['a-link'],
            'expirationDays' => 7,
        ]);

        $this->assertSame($basket, $request->context['basket']);
        $this->assertSame(['a-link'], $request->context['downloadLinks']);
        $this->assertSame(7, $request->context['expirationDays']);
    }

    // A blank key must come back as null, so UiBundle falls back on the site-wide address instead of building a broken one
    public function testABlankAddressKeyIsLeftToTheSiteWideFallback(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['shop-name', 'My shop'],
            ['shop-email-from', ''],
            ['shop-email-from-name', ''],
            ['shop-email-reply-to', ''],
            ['shop-email-reply-to-name', ''],
            ['shop-email-bcc', ''],
        ]);

        $request = new BasketEmailFactory($configService, $this->translator())->create($this->basket(), 'label.confirm_order', 'confirm_order');

        $this->assertNull($request->from);
        $this->assertNull($request->replyTo);
        $this->assertNull($request->bcc);
    }

    private function factory(): BasketEmailFactory
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['shop-name', 'My shop'],
            ['shop-email-from', 'orders@shop.test'],
            ['shop-email-from-name', 'Shop name'],
            ['shop-email-reply-to', 'contact@shop.test'],
            ['shop-email-reply-to-name', 'Shop contact'],
            ['shop-email-bcc', 'archive@shop.test'],
        ]);

        return new BasketEmailFactory($configService, $this->translator());
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
