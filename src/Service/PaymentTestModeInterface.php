<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

interface PaymentTestModeInterface
{
    // Whether the site charges with the provider's test keys, which is what the payment-test-mode config holds - the one answer telling a real order from a rehearsal, read by the gateway to pick its keys, by the order number to carry its TEST- prefix and by the banner shown to the customer.
    public function isEnabled(): bool;
}
