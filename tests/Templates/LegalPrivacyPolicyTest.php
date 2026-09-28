<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;

// UiBundle's "france/privacy-policy" model includes these by name, "ignore missing" hiding any mistake: each locale must exist and name Stripe in its own unit
class LegalPrivacyPolicyTest extends TestCase
{
    public function testEachLocaleNamesThePaymentProvider(): void
    {
        foreach (['fr', 'en', 'es'] as $locale) {
            $path = \dirname(__DIR__, 2) . '/templates/legal/privacy-policy.' . $locale . '.html.twig';
            $this->assertFileExists($path);

            $template = (string) file_get_contents($path);
            $this->assertStringContainsString('<h3 data-legal-id="third-parties.stripe">', $template, $locale);
            $this->assertStringContainsString('Stripe', $template, $locale);
        }
    }
}
