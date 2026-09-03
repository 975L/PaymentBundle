<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\UiBundle\Contract\BlockCacheTagProviderInterface;
use c975L\UiBundle\Entity\Block;

// The delivery block states what shipping starts at, read off the grid and off two settings at render time (see components/Basket/Shipping.html.twig) - neither of which a Block event ever signals a change of. Its entry therefore carries a tag of its own, dropped by PaymentCacheInvalidationListener whenever the grid or one of those settings is saved
class PaymentBlockCacheTagProvider implements BlockCacheTagProviderInterface
{
    public function getCacheTagResolvers(): array
    {
        return [
            'payment_shipping' => static fn (Block $block): array => [PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING],
        ];
    }
}
