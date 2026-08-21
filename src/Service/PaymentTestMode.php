<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;

// A stated setting rather than a key sniffed for the word "test": a live key holding it anywhere would have turned a real shop into a rehearsal, and a test key not holding it charged nobody while the site claimed it did
class PaymentTestMode implements PaymentTestModeInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->configService->get('payment-test-mode');
    }
}
