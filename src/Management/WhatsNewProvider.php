<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\WhatsNewJsonReader;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;

// Contributes this bundle's own news to the dashboard's What's new, ConfigBundle merging every bundle's entries by date
class WhatsNewProvider implements WhatsNewProviderInterface
{
    public function getEntries(): array
    {
        return WhatsNewJsonReader::read(\dirname(__DIR__, 2) . '/config/whatsnew.json');
    }
}
