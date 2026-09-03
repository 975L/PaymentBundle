<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Service\PaymentBlockCacheInvalidator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// The one place the delivery block's own tag is dropped from
class PaymentBlockCacheInvalidatorTest extends TestCase
{
    public function testItDropsTheTagTheBlockCarries(): void
    {
        $dropped = [];
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willReturnCallback(function (array $tags) use (&$dropped): bool {
            $dropped[] = $tags;

            return true;
        });

        new PaymentBlockCacheInvalidator($cache)->invalidateShipping();

        $this->assertSame([[PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING]], $dropped);
    }

    // The tag the provider puts on the entry and the one dropped here are the same string, or nothing is ever invalidated
    public function testTheTagIsTheOneTheProviderAnnounces(): void
    {
        $this->assertSame('payment_shipping', PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING);
    }
}
