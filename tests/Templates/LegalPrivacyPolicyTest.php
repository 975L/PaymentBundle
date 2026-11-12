<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

// UiBundle's "france/privacy-policy" model includes these by name, "ignore missing" hiding any mistake: each locale must exist and name the providers the shop offers in its payment unit, the app stores only on a site selling in an app
class LegalPrivacyPolicyTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function locales(): iterable
    {
        yield ['fr'];
        yield ['en'];
        yield ['es'];
    }

    #[DataProvider('locales')]
    public function testStripeAloneWithoutAStore(string $locale): void
    {
        $html = $this->render($locale, []);
        $this->assertStringContainsString('<h3 data-legal-id="third-parties.stripe">', $html);
        $this->assertStringContainsString('>Stripe</a>', $html);
        $this->assertStringNotContainsString('Revolut', $html);
        $this->assertStringNotContainsString('third-parties.app-stores', $html);
    }

    // A shop taking Revolut alone names Revolut, never Stripe
    #[DataProvider('locales')]
    public function testTheProvidersOfferedAreTheOnesNamed(string $locale): void
    {
        $html = $this->render($locale, [], ['revolut']);
        $this->assertStringContainsString('(Revolut)', $html);
        $this->assertStringNotContainsString('Stripe', $html);

        $both = $this->render($locale, [], ['stripe', 'revolut']);
        $this->assertStringContainsString('>Stripe</a>, Revolut)', $both);
    }

    #[DataProvider('locales')]
    public function testTheAppStoresOnceOneIsSetUp(string $locale): void
    {
        $html = $this->render($locale, ['google-play']);
        $this->assertStringContainsString('<h3 data-legal-id="third-parties.stripe">', $html);
        $this->assertStringContainsString('<h3 data-legal-id="third-parties.app-stores">', $html);
    }

    // The template alone, the stores and the providers stubbed
    /**
     * @param list<string> $stores
     * @param list<string> $gateways
     */
    private function render(string $locale, array $stores, array $gateways = ['stripe']): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates/legal'));
        $twig->addFunction(new TwigFunction('payment_in_app_stores', static fn (): array => $stores));
        $twig->addFunction(new TwigFunction('payment_gateways', static fn (): array => $gateways));

        return $twig->render('privacy-policy.' . $locale . '.html.twig');
    }
}
