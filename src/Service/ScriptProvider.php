<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\UiBundle\Contract\BundleScriptProviderInterface;

// The basket controller is needed on every page carrying an add button, not only on the basket ones, so the front layout loads it site-wide
class ScriptProvider implements BundleScriptProviderInterface
{
    public function getScripts(): array
    {
        return [
            '@c975l/payment-bundle/controllers.js',
        ];
    }
}
