<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Service\GalleryShowcaseProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

// What the showcase says of "payment_shipping" on a site that sells nothing. The amounts handed to the component are the whole point: read from the configuration instead, they would all be unset there and the block would show three empty frames - the very hole this provider fills
class GalleryShowcaseProviderTest extends TestCase
{
    // The real component is not rendered here: it reads config() and formats a currency, neither of which a bare Environment offers. What it receives is what this provider is responsible for, so the stub prints it back
    private function createProvider(): GalleryShowcaseProvider
    {
        $twig = new Environment(new ArrayLoader([
            '@c975LPayment/components/Basket/Shipping.html.twig' => '{{ shipping|default("none") }}|{{ free|default("none") }}|{{ currency }}',
        ]));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new GalleryShowcaseProvider($twig, $translator);
    }

    // Stands in for its own kind, so the showcase suppresses that kind's own empty preview card
    public function testTheShowcaseStandsInForTheShippingBlockKind(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame(['label.block_shipping'], array_keys($showcases));
        $this->assertSame('payment_shipping', $showcases['label.block_shipping']['kind']);
        $this->assertSame('label.block_shipping_description', $showcases['label.block_shipping']['description']);
    }

    // One variant per sentence the component can say - a single one would show a third of what the block offers a page
    public function testEachVariantHandsOverTheAmountsMakingTheComponentSayItsOwnSentence(): void
    {
        $variants = $this->createProvider()->getShowcases()['label.block_shipping']['variants'];

        $this->assertSame(['Seuil de gratuité', 'Forfait', 'Livraison offerte'], array_keys($variants));
        $this->assertSame('none|5000|EUR', $variants['Seuil de gratuité']);
        $this->assertSame('490|0|EUR', $variants['Forfait']);
        $this->assertSame('0|0|EUR', $variants['Livraison offerte']);
    }
}
