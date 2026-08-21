<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\PaymentBundle\Contract\BasketItemProviderInterface;

class BasketItemProviderRegistry
{
    /** @var array<string, BasketItemProviderInterface> */
    private array $providers = [];

    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getKind()] = $provider;
        }
    }

    public function get(string $kind): BasketItemProviderInterface
    {
        if (!isset($this->providers[$kind])) {
            throw new \InvalidArgumentException(sprintf('No BasketItemProviderInterface registered for kind "%s"', $kind));
        }

        return $this->providers[$kind];
    }

    public function has(string $kind): bool
    {
        return isset($this->providers[$kind]);
    }

    /** @return string[] */
    public function getKinds(): array
    {
        return array_keys($this->providers);
    }
}
