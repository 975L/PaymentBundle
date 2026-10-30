<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\PaymentBundle\Contract\InAppStoreInterface;

// Every store an app sells through, found by their slug - the notification route and the purchase endpoint of a site both name one
class InAppStoreRegistry
{
    /** @var array<string, InAppStoreInterface> */
    private array $stores = [];

    /** @param iterable<InAppStoreInterface> $stores */
    public function __construct(iterable $stores)
    {
        foreach ($stores as $store) {
            $this->stores[$store->getSlug()] = $store;
        }
    }

    // Whether a store answers to the slug
    public function has(string $slug): bool
    {
        return isset($this->stores[$slug]);
    }

    // The store answering to the slug, an unknown one being a programming error
    public function get(string $slug): InAppStoreInterface
    {
        if (!isset($this->stores[$slug])) {
            throw new \InvalidArgumentException(sprintf('No InAppStoreInterface registered for slug "%s"', $slug));
        }

        return $this->stores[$slug];
    }

    // Every store, keyed by slug
    /** @return array<string, InAppStoreInterface> */
    public function all(): array
    {
        return $this->stores;
    }
}
