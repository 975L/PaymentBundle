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
use c975L\PaymentBundle\Service\PaymentBlockCacheTagProvider;
use c975L\UiBundle\Entity\Block;
use PHPUnit\Framework\TestCase;

// What lets the delivery block be cached at all: the tag its entry carries
class PaymentBlockCacheTagProviderTest extends TestCase
{
    public function testTheDeliveryBlockIsTheOnlyKindCovered(): void
    {
        $this->assertSame(['payment_shipping'], array_keys(new PaymentBlockCacheTagProvider()->getCacheTagResolvers()));
    }

    public function testItCarriesTheTagTheGridAndTheSettingsDrop(): void
    {
        $resolvers = new PaymentBlockCacheTagProvider()->getCacheTagResolvers();

        $this->assertSame([PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING], $resolvers['payment_shipping'](new Block()));
    }
}
