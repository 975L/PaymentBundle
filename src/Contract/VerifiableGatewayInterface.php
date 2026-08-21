<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Implemented, on top of PaymentGatewayInterface, by a provider whose credentials can be checked against its API rather than only read from the config. Kept apart from PaymentGatewayInterface on purpose: a provider offering no such call stays valid, and adding this never breaks one that already exists
// Only ever called from c975l:health-check:run, never from a controller - it reaches a third party, and a dashboard page may not block on that
interface VerifiableGatewayInterface
{
    /**
     * Asks the provider whether the configured keys actually authenticate.
     *
     * @return string|null null when they do; the reason they do not otherwise, ready to be shown to an admin
     */
    public function verifyCredentials(): ?string;
}
