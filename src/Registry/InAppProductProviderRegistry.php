<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\PaymentBundle\Contract\InAppProductProviderInterface;

// Every provider of what a site sells in its app, and the one a product id belongs to
class InAppProductProviderRegistry
{
    /** @param iterable<InAppProductProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /** @return iterable<InAppProductProviderInterface> */
    public function all(): iterable
    {
        return $this->providers;
    }

    public function forProduct(string $productId): ?InAppProductProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($productId)) {
                return $provider;
            }
        }

        return null;
    }
}
