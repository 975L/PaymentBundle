<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Store;

// The one way the store classes read a setting, for a class holding a ConfigServiceInterface as $configService
trait StoreConfigTrait
{
    // The setting trimmed, a key pasted into the back-office often carrying a stray newline, and an empty string when unset
    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }
}
