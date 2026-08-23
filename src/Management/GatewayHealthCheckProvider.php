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
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\VerifiableGatewayInterface;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;

// Whether the keys of every provider the shop offers actually authenticate, which is the one thing PaymentAlertProvider cannot say: it reads the config and sees a key, where a revoked, rotated or mistyped one looks exactly the same until a customer tries to pay
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
        $mode = $this->paymentTestMode->isEnabled() ? 'test' : 'live';

        // One row per provider the customer may be sent to, not only the default: a second provider whose key was revoked charges nobody, and nothing else would say so
        // A shop offering none is a site that does not sell, not a site that is broken - PaymentAlertProvider is the one that speaks up when a provider is named without its keys
        $results = [];
        foreach ($this->paymentGatewayRegistry->getOffered() as $slug => $gateway) {
            $results[] = $this->check($slug, $gateway, $mode);
        }

        return $results;
    }

    private function check(string $slug, PaymentGatewayInterface $gateway, string $mode): array
    {
        $label = $slug . ' (' . $mode . ')';

        // A provider that cannot be asked is reported as unchecked rather than left out, so the dashboard says why nothing is known about it
        if (!$gateway instanceof VerifiableGatewayInterface) {
            return [
                'url' => $slug,
                'label' => $label,
                'status' => HealthCheckResult::STATUS_SKIPPED,
                'summary' => 'This provider offers no way to verify its credentials',
            ];
        }

        $error = $gateway->verifyCredentials();

        return [
            'url' => $slug,
            'label' => $label,
            'status' => null === $error ? HealthCheckResult::STATUS_OK : HealthCheckResult::STATUS_ERROR,
            'summary' => $error ?? 'The ' . $mode . ' keys authenticate',
            'details' => ['gateway' => $slug, 'mode' => $mode],
        ];
    }
}
