<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Registry;

use c975L\PaymentBundle\Contract\BasketRecommendationProviderInterface;
use c975L\PaymentBundle\Entity\Basket;

class BasketRecommendationRegistry
{
    /** @var BasketRecommendationProviderInterface[] */
    private array $providers = [];

    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    // Only one recommendation provider is expected - the first registered wins, [] if none installed
    public function getRecommendations(Basket $basket, int $limit): array
    {
        return [] === $this->providers ? [] : $this->providers[0]->getRecommendations($basket, $limit);
    }

    // The template drawing them, that of the same first provider - null when none is installed, the page then showing no recommendations at all
    public function getTemplate(): ?string
    {
        return [] === $this->providers ? null : $this->providers[0]->getTemplate();
    }
}
