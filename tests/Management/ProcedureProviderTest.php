<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\PaymentBundle\Management\ProcedureProvider;
use PHPUnit\Framework\TestCase;

class ProcedureProviderTest extends TestCase
{
    // Reads the bundle's own config/procedures.json and turns each row into a slug + title + body entry
    public function testGetProceduresReturnsOneEntryPerJsonRow(): void
    {
        $rawEntries = json_decode(file_get_contents(\dirname(__DIR__, 2) . '/config/procedures.json'), true);

        $entries = new ProcedureProvider()->getProcedures();

        $this->assertCount(\count($rawEntries), $entries);
        $this->assertSame($rawEntries[0]['slug'], $entries[0]['slug']);
        $this->assertNotSame('', $entries[0]['title']);
        $this->assertNotSame('', $entries[0]['body']);
    }

    // Every procedure is written in the three languages the back-office is read in, a missing one falling back to English and reading as an oversight
    public function testEveryProcedureIsTranslatedInEveryLocale(): void
    {
        $rawEntries = json_decode(file_get_contents(\dirname(__DIR__, 2) . '/config/procedures.json'), true);

        foreach ($rawEntries as $entry) {
            foreach (['fr', 'en', 'es'] as $locale) {
                $this->assertArrayHasKey($locale, $entry['title'], $entry['slug']);
                $this->assertArrayHasKey($locale, $entry['body'], $entry['slug']);
            }
        }
    }
}
