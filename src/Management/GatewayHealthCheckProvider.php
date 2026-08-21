<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\PaymentBundle\Contract\VerifiableGatewayInterface;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;

// Whether the active gateway's keys actually authenticate, which is the one thing PaymentAlertProvider cannot say: it reads the config and sees a key, where a revoked, rotated or mistyped one looks exactly the same until a customer tries to pay
// Run from c975l:health-check:run only - it reaches the provider's API, and no dashboard page may block on that
class GatewayHealthCheckProvider implements HealthCheckProviderInterface
{
    public const string KIND = 'payment-gateway';

    public function __construct(
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentTestModeInterface $paymentTestMode,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    public function runChecks(): array
    {
        $gateway = $this->paymentGatewayRegistry->getActiveOrNull();

        // No gateway configured at all is a site that does not sell, not a site that is broken - PaymentAlertProvider is the one that speaks up when one is named without its keys
        if (null === $gateway) {
            return [];
        }

        $mode = $this->paymentTestMode->isEnabled() ? 'test' : 'live';
        $label = $gateway->getSlug() . ' (' . $mode . ')';

        // A provider that cannot be asked is reported as unchecked rather than left out, so the dashboard says why nothing is known about it
        if (!$gateway instanceof VerifiableGatewayInterface) {
            return [[
                'url' => $gateway->getSlug(),
                'label' => $label,
                'status' => HealthCheckResult::STATUS_SKIPPED,
                'summary' => 'This provider offers no way to verify its credentials',
            ]];
        }

        $error = $gateway->verifyCredentials();

        return [[
            'url' => $gateway->getSlug(),
            'label' => $label,
            'status' => null === $error ? HealthCheckResult::STATUS_OK : HealthCheckResult::STATUS_ERROR,
            'summary' => $error ?? 'The ' . $mode . ' keys authenticate',
            'details' => ['gateway' => $gateway->getSlug(), 'mode' => $mode],
        ]];
    }
}
