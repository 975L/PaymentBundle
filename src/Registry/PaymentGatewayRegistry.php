<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;

class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function __construct(
        iterable $gateways,
        private readonly ConfigServiceInterface $configService,
    ) {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->getSlug()] = $gateway;
        }
    }

    /**
     * The providers a customer chooses between, the default one first.
     *
     * A provider is offered as soon as its keys are filled in for the mode in use - which is what isConfigured()
     * answers - so a shop opens a second one by storing its keys and closes it by clearing them. There is no
     * second list to keep in step with the keys, and therefore no way for the two to disagree.
     *
     * @return array<string, PaymentGatewayInterface> keyed by slug
     */
    public function getOffered(): array
    {
        $offered = [];
        foreach ($this->gateways as $slug => $gateway) {
            if ($gateway->isConfigured()) {
                $offered[$slug] = $gateway;
            }
        }

        // The one payment-gateway names leads, which is the one the basket pre-selects; it is simply one of the others when its own keys are missing
        $default = (string) $this->configService->get('payment-gateway');
        if (isset($offered[$default])) {
            $offered = [$default => $offered[$default]] + $offered;
        }

        return $offered;
    }

    // The provider a checkout opens with when the customer named none, and the one pre-selected when they are given the choice - what the payment-gateway config holds
    public function getActive(): PaymentGatewayInterface
    {
        return $this->get((string) $this->configService->get('payment-gateway'));
    }

    // The default gateway, or null when the site cannot charge at all - "payment-gateway" left empty or naming a provider no bundle registers, which getActive() answers with an exception. Both are an admin's typo away, so what reads it to decide (see PaymentAlertProvider, BasketService::validate()) asks here instead of guarding a throw
    public function getActiveOrNull(): ?PaymentGatewayInterface
    {
        $slug = (string) $this->configService->get('payment-gateway');

        return $this->gateways[$slug] ?? null;
    }

    public function get(string $slug): PaymentGatewayInterface
    {
        if (!isset($this->gateways[$slug])) {
            throw new \InvalidArgumentException(sprintf('No PaymentGatewayInterface registered for slug "%s"', $slug));
        }

        return $this->gateways[$slug];
    }

    public function has(string $slug): bool
    {
        return isset($this->gateways[$slug]);
    }

    /** @return string[] */
    public function getSlugs(): array
    {
        return array_keys($this->gateways);
    }
}
