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
use c975L\PaymentBundle\Contract\CatalogueBasketItemProviderInterface;

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

    // The listing the basket sends the customer back to, taken from the first provider that has one - null when nothing installed sells out of a catalogue (see CatalogueBasketItemProviderInterface)
    public function getCatalogueUrl(): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CatalogueBasketItemProviderInterface && null !== $url = $provider->getCatalogueUrl()) {
                return $url;
            }
        }

        return null;
    }

    /** @return string[] */
    public function getKinds(): array
    {
        return array_keys($this->providers);
    }
}
