<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Registry\InAppProductProviderRegistry;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;

// Pushes what the sites' providers sell into every store holding the keys to write it, as BookBundle pushes its books to Google Books: the database is the only place a product is ever set up. A store failing on one product does not stop the others, its reason is reported instead
class InAppCatalogPublisher
{
    public function __construct(
        private readonly InAppStoreRegistry $storeRegistry,
        private readonly InAppProductProviderRegistry $providerRegistry,
    ) {
    }

    // Publishes every product, or the one named
    /** @return list<array{product: string, store: string, error: ?string}> one line per product and store, error null when written */
    public function publish(?string $productId = null): array
    {
        $stores = array_filter($this->storeRegistry->all(), static fn ($store): bool => $store->canPublish());
        $report = [];
        foreach ($this->providerRegistry->all() as $provider) {
            foreach ($provider->getProducts() as $product) {
                if (null !== $productId && $productId !== $product->productId) {
                    continue;
                }
                foreach ($stores as $slug => $store) {
                    try {
                        $store->publishProduct($product);
                        $report[] = ['product' => $product->productId, 'store' => $slug, 'error' => null];
                    } catch (\Throwable $e) {
                        $report[] = ['product' => $product->productId, 'store' => $slug, 'error' => $e->getMessage()];
                    }
                }
            }
        }

        return $report;
    }
}
