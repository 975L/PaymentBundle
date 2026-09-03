<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use Symfony\Contracts\Cache\TagAwareCacheInterface;

// The tag the delivery block carries, and the one place it is dropped from. UiBundle's BlockCacheInvalidationListener only ever invalidates the changed Block itself, and knows nothing of the shipping grid nor of the settings that block states its prices from
class PaymentBlockCacheInvalidator
{
    public const string CACHE_TAG_SHIPPING = 'payment_shipping';

    public function __construct(private readonly TagAwareCacheInterface $cache)
    {
    }

    public function invalidateShipping(): void
    {
        $this->cache->invalidateTags([self::CACHE_TAG_SHIPPING]);
    }
}
