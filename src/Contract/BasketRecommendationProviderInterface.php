<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use c975L\PaymentBundle\Entity\Basket;

// Optional cross-sell hook; with none registered the basket simply shows no recommendations.
interface BasketRecommendationProviderInterface
{
    /**
     * @param int $limit the most entries to return, the caller rendering them all
     *
     * @return object[] the provider's own entities, ready for its own template - return [] when the basket gives nothing to recommend from
     */
    public function getRecommendations(Basket $basket, int $limit): array;

    // The template drawing what getRecommendations() returns, handed the entries as a "recommendations" variable - the entities are the provider's own, so is the markup showing them
    public function getTemplate(): string;
}
