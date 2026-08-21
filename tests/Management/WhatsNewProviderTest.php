<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\PaymentBundle\Management\WhatsNewProvider;
use PHPUnit\Framework\TestCase;

class WhatsNewProviderTest extends TestCase
{
    // Reads the bundle's own config/whatsnew.json and turns each row into a date + description entry
    public function testGetEntriesReturnsOneEntryPerJsonRow(): void
    {
        $rawEntries = json_decode(file_get_contents(\dirname(__DIR__, 2) . '/config/whatsnew.json'), true);

        $entries = new WhatsNewProvider()->getEntries();

        $this->assertCount(\count($rawEntries), $entries);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entries[0]['date']);
        $this->assertSame($rawEntries[0]['date'], $entries[0]['date']->format('Y-m-d'));
        $this->assertIsArray($entries[0]['description']);
        $this->assertNotSame('', $entries[0]['description'][0]);
    }

    // Every description of every row carries the three locales the bundle's translations cover, so no visitor lands on another language's wording
    public function testEveryDescriptionIsTranslatedInTheThreeLocales(): void
    {
        $rawEntries = json_decode(file_get_contents(\dirname(__DIR__, 2) . '/config/whatsnew.json'), true);

        foreach ($rawEntries as $entry) {
            foreach ($entry['description'] as $description) {
                $this->assertSame(['en', 'fr', 'es'], array_keys($description));
            }
        }
    }
}
