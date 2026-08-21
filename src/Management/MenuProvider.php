<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;

class MenuProvider implements MenuProviderInterface
{
    public function getMenuSection(): array
    {
        return [
            'label' => 'label.payment',
            'translation_domain' => 'payment',
        ];
    }

    public function getMenus(): array
    {
        return [
            'basket' => [
                'controller' => BasketCrudController::class,
                'label' => 'label.baskets',
                'translation_domain' => 'payment',
                'icon' => 'fas fa-basket-shopping',
                'description' => 'description.baskets',
            ],
            'payment' => [
                'controller' => PaymentCrudController::class,
                'label' => 'label.payments',
                'translation_domain' => 'payment',
                'icon' => 'fas fa-money-bill-wave',
                'description' => 'description.payments',
            ],
        ];
    }

    public function getLinks(): array
    {
        return [];
    }
}
