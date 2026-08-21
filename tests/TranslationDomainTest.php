<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests;

use PHPUnit\Framework\TestCase;

// This bundle spoke the "shop" domain until 21/08/2026, whose catalog lives in ShopBundle - which requires this package, not the other way round. A site running Payment without Shop (CrowdfundingBundle does) showed raw keys across the whole checkout. These tests keep it from coming back
class TranslationDomainTest extends TestCase
{
    private const array LOCALES = ['en', 'fr', 'es'];

    // "site" is SiteBundle's own, shipped by a bundle every satellite depends on, and stays legitimate
    private const array FOREIGN_DOMAINS = ['shop', 'crowdfunding', 'book', 'gallery', 'social'];

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (['/../src', '/../templates'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . $directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && \in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    // Nothing here may name another satellite's catalog: that bundle may simply not be installed
    public function testNoFileSpeaksAnotherBundleDomain(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            foreach (self::FOREIGN_DOMAINS as $domain) {
                $this->assertDoesNotMatchRegularExpression(
                    sprintf("/trans_default_domain\\s+'%s'|,\\s*'%s'\\s*\\)|'translation_domain'\\s*=>\\s*'%s'/", $domain, $domain, $domain),
                    $contents,
                    sprintf('%s names the "%s" domain, whose catalog this bundle does not ship', basename($file), $domain)
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        $xliff = simplexml_load_file(__DIR__ . '/../translations/payment.' . $locale . '.xlf');
        $translations = [];
        foreach ($xliff->file->body->{'trans-unit'} as $unit) {
            $translations[(string) $unit->source] = (string) $unit->target;
        }

        return $translations;
    }

    // A key present in one locale and not in the others shows up raw for part of the visitors only, which is the hardest kind to notice
    public function testEveryLocaleCarriesTheSameKeys(): void
    {
        $reference = array_keys($this->catalog('en'));
        sort($reference);

        foreach (self::LOCALES as $locale) {
            $keys = array_keys($this->catalog($locale));
            sort($keys);

            $this->assertSame($reference, $keys, sprintf('The %s catalog does not carry the same keys as the English one', $locale));
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->catalog($locale) as $key => $value) {
                $this->assertNotSame('', $value, sprintf('"%s" is empty in the %s catalog', $key, $locale));
            }
        }
    }
}
