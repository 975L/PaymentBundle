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

// UiBundle's "france/terms-of-sales" model includes these by name, "ignore missing" hiding any mistake: each locale must exist, and its "in-app-purchases" unit show only on a site selling in an app
class LegalTermsOfSalesTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function locales(): iterable
    {
        yield ['fr', "<h2>Achats dans l'application</h2>"];
        yield ['en', '<h2>In-app purchases</h2>'];
        yield ['es', '<h2>Compras en la aplicación</h2>'];
    }

    #[DataProvider('locales')]
    public function testNothingWithoutAStore(string $locale, string $title): void
    {
        $this->assertSame('', trim($this->render($locale, [])));
    }

    #[DataProvider('locales')]
    public function testTheSectionOnceAStoreIsSetUp(string $locale, string $title): void
    {
        $html = $this->render($locale, ['apple']);
        $this->assertStringContainsString('<section data-legal-id="in-app-purchases">', $html);
        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('L 221-28', $html);
        $this->assertStringContainsString('Example site', $html);
    }

    // The template alone, the stores and legal_var() stubbed, an unknown legal var failing rather than rendering empty
    /** @param list<string> $stores */
    private function render(string $locale, array $stores): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates/legal'));
        $twig->addFunction(new TwigFunction('payment_in_app_stores', static fn (): array => $stores));
        $twig->addFunction(new TwigFunction('legal_var', static fn (string $slug): string => 'site-name' === $slug ? 'Example site' : throw new \LogicException('Unknown legal var ' . $slug)));

        return $twig->render('terms-of-sales.' . $locale . '.html.twig');
    }
}
