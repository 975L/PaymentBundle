<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\PaymentBundle\Contract\BasketDownloadProviderInterface;
use c975L\PaymentBundle\Entity\Basket;

class BasketDownloadRegistry
{
    /** @var BasketDownloadProviderInterface[] */
    private array $providers = [];

    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    /**
     * Every downloadable file of that basket, gathered from all providers - unlike recommendations, a basket can hold files of several kinds at once.
     *
     * @return list<array{title: string, url: string, size: ?int}>
     */
    public function getDownloads(Basket $basket): array
    {
        $downloads = [];
        foreach ($this->providers as $provider) {
            $downloads = [...$downloads, ...$provider->getDownloads($basket)];
        }

        return $downloads;
    }

    // Whether anything at all can offer downloads, so the customer area leaves the section out rather than drawing an empty one
    public function hasProviders(): bool
    {
        return [] !== $this->providers;
    }
}
