<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Translation;

use PHPUnit\Framework\TestCase;

// A narration is never drawn, so a key missing from its catalogue shows nowhere: the films read the fallback and nobody notices until one is filmed. Nothing else checks these - TranslationDomainTest only sees keys passed to trans(), where a narration is declared in an array
class NarrationCatalogueTest extends TestCase
{
    // The bundle's own narrations, resolved in its "payment" domain suffixed "_narration" - spoken and never drawn, hence the two locales the rest of the bundle does not stop at
    private const array LOCALES = ['en', 'fr'];

    public function testEveryDeclaredNarrationIsShippedInBothLocales(): void
    {
        $declared = $this->declared();
        $this->assertNotEmpty($declared, 'No narration declared in "src/", the test itself is broken.');

        foreach (self::LOCALES as $locale) {
            $catalogue = $this->catalogue($locale);

            foreach ($declared as $file => $keys) {
                foreach ($keys as $key) {
                    $this->assertContains($key, $catalogue, sprintf('"%s" declares "%s", which "payment_narration.%s.xlf" does not ship.', $file, $key, $locale));
                }
            }
        }
    }

    // The other way round: a narration nobody declares any more is dead weight in a catalogue written by hand
    public function testNoNarrationIsShippedWithoutBeingDeclared(): void
    {
        $declared = array_merge(...array_values($this->declared()));

        foreach ($this->catalogue('en') as $key) {
            $this->assertContains($key, $declared, sprintf('"payment_narration.en.xlf" ships "%s", which nothing in "src/" declares any more.', $key));
        }
    }

    // Every narration key named in a menu entry or a guided project step, keyed by the file naming it
    private function declared(): array
    {
        $declared = [];

        foreach ($this->phpFiles() as $file) {
            preg_match_all('/\'narration\'\s*=>\s*\'([a-z][a-zA-Z0-9_]*\.[a-zA-Z0-9_.-]+)\'/', (string) file_get_contents($file), $matches);

            if ($matches[1]) {
                $declared[substr($file, strlen($this->root()) + 1)] = $matches[1];
            }
        }

        return $declared;
    }

    private function catalogue(string $locale): array
    {
        $path = $this->root() . '/translations/payment_narration.' . $locale . '.xlf';
        $this->assertFileExists($path);
        $keys = [];

        foreach (simplexml_load_file($path)->file->body->{'trans-unit'} as $unit) {
            $keys[] = (string) $unit->source;
        }

        return $keys;
    }

    private function phpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src'));

        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
