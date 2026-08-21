<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\ImportmapProviderInterface;

// Same import name as ScriptProvider - that one tells the front layout which scripts to load, this one tells c975l:config:check-importmap what importmap.php entry it needs
class ImportmapProvider implements ImportmapProviderInterface
{
    public function getAdminImportmapEntries(): array
    {
        return [];
    }

    public function getImportmapEntries(): array
    {
        return [
            '@c975l/payment-bundle/controllers.js' => [
                'path' => 'assets/controllers.js',
                'entrypoint' => true,
            ],
        ];
    }
}
