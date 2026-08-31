<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use Twig\Attribute\AsTwigFunction;

// What a page states delivery starts at, the grid holding one price per zone and per weight rather than the single rate "shop-shipping" used to name (see ShippingZone)
class ShippingExtension
{
    public function __construct(private readonly ShippingRateResolverInterface $shippingRateResolver)
    {
    }

    // Null when the shop has written no tier at all: the block then says nothing rather than promising free delivery
    #[AsTwigFunction('payment_shipping_from')]
    public function from(): ?int
    {
        return $this->shippingRateResolver->cheapest();
    }
}
