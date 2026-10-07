<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Implemented, on top of BasketItemProviderInterface, by a provider whose lines can be posted. Kept apart on purpose, like WeighableBasketItemProviderInterface: a provider selling only credits, links or downloads ships nothing, and an empty delivery grid is then nothing to warn about (see ShippingHealthCheckProvider). Opt-in: a provider selling anything posted must implement it, one that does not being taken for shipping nothing
interface ShippingBasketItemProviderInterface
{
    // Whether this provider currently offers anything that is posted - false for a print service switched off, so a site that turned it off is not told to fill a grid it does not need
    public function shipsParcels(): bool;
}
