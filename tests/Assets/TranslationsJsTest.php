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

// A locale dropped from translations.js falls back to English, a missing key renders raw to the visitor
class TranslationsJsTest extends TestCase
{
    private const array LOCALES = ['en', 'es', 'fr'];

    public function testEveryShippedLocaleIsPresent(): void
    {
        $translations = $this->loadTranslations();

        foreach (self::LOCALES as $locale) {
            $this->assertArrayHasKey($locale, $translations);
        }
    }

    // A key present in one locale only is a key that renders raw for every other visitor
    public function testEveryLocaleCarriesTheSameKeys(): void
    {
        $translations = $this->loadTranslations();
        $reference = array_keys($translations['en']);

        foreach (self::LOCALES as $locale) {
            $this->assertSame($reference, array_keys($translations[$locale]), sprintf('The "%s" locale does not carry the same keys as "en"', $locale));
        }
    }

    // Every key the controller asks for must exist, or Handlers.translate() hands the raw key to the visitor
    public function testTheControllerAsksForNoUnknownKey(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/js/basket.js');
        preg_match_all('/Handlers\.translate\(\s*"([^"]+)"/', $script, $matches);

        $this->assertNotEmpty($matches[1], 'basket.js asks for no translation, the test itself is broken.');

        $available = array_keys($this->loadTranslations()['en']);
        foreach (array_unique($matches[1]) as $key) {
            $this->assertContains($key, $available, sprintf('basket.js asks for "%s", which translations.js does not carry.', $key));
        }
    }

    // The three error keys the controller names through a variable, one per route it calls, which the regexp above cannot see
    public function testEveryErrorKeyThePagesFailWithIsCarried(): void
    {
        $available = array_keys($this->loadTranslations()['en']);

        foreach (['basket.add.error', 'basket.delete.error', 'basket.load.error', 'product.delete.error'] as $key) {
            $this->assertContains($key, $available);
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach ($this->loadTranslations() as $locale => $messages) {
            foreach ($messages as $key => $message) {
                $this->assertNotSame('', $message, sprintf('"%s" has an empty %s translation', $key, $locale));
            }
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadTranslations(): array
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/js/translations.js');

        // The module is "export default { ... };" around plain JSON, so the object literal decodes as-is
        $start = strpos($script, '{');
        $end = strrpos($script, '}');
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        return json_decode(substr($script, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);
    }
}
