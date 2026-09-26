<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Account\AccountSectionProviderInterface;
use c975L\ConfigBundle\Contract\UserInterface;
use c975L\PaymentBundle\Repository\BasketRepository;

// The member's latest orders on their own page (see ConfigBundle's AccountController), the whole history staying on /account/orders
class AccountSectionProvider implements AccountSectionProviderInterface
{
    // As many as a glance takes in, the rest one click away
    private const int LATEST_ORDERS = 3;

    public function __construct(private readonly BasketRepository $basketRepository)
    {
    }

    // Shown even before the first order, which says so and is where the history will be
    public function getAccountSections(UserInterface $user): array
    {
        return [[
            'title' => 'label.my_orders',
            'translation_domain' => 'payment',
            'template' => '@c975LPayment/customer/_account_section.html.twig',
            'context' => ['baskets' => $this->basketRepository->findPaidByUser($user, self::LATEST_ORDERS)],
            'position' => 10,
        ]];
    }
}
