<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;

// Exposes as SiteBundle Menu targets (navbar/footer) the two public pages of this bundle a visitor navigates to on purpose: the basket, and the order history of a logged-in buyer. The rest are steps of a checkout, reached from the basket and never from a menu
// Nothing is stored but the target itself: the url is generated at render time, so renaming the route prefix leaves no menu item behind
class LinkableRouteProvider implements LinkableRouteProviderInterface
{
    public function getLinkableRoutes(): array
    {
        return [
            'basket_display' => [
                'label' => 'label.basket',
                'translation_domain' => 'payment',
            ],
            'customer_orders' => [
                'label' => 'label.my_orders',
                'translation_domain' => 'payment',
            ],
        ];
    }
}
