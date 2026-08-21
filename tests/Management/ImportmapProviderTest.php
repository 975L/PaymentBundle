<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\PaymentBundle\Management\ImportmapProvider;
use PHPUnit\Framework\TestCase;

class ImportmapProviderTest extends TestCase
{
    // The entry the consuming app needs for AssetMapper to serve the barrel, "entrypoint" included - a plain entry is loaded by nothing
    public function testGetImportmapEntriesReturnsControllersEntrypoint(): void
    {
        $entries = new ImportmapProvider()->getImportmapEntries();

        $this->assertSame([
            '@c975l/payment-bundle/controllers.js' => [
                'path' => 'assets/controllers.js',
                'entrypoint' => true,
            ],
        ], $entries);
    }

    // The bundle ships no back-office controller
    public function testGetAdminImportmapEntriesIsEmpty(): void
    {
        $this->assertSame([], new ImportmapProvider()->getAdminImportmapEntries());
    }
}
