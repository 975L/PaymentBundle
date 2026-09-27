<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Account\AccountDataProviderInterface;
use c975L\ConfigBundle\Contract\UserInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;

// The orders part of a member's data export (ConfigBundle's /account/export): every basket tied to the account, with what it holds and where it was sent
class AccountDataProvider implements AccountDataProviderInterface
{
    public function __construct(private readonly BasketRepository $basketRepository)
    {
    }

    // Under "orders", newest first
    public function getAccountData(UserInterface $user): array
    {
        $baskets = $this->basketRepository->findBy(['user' => $user], ['creation' => 'DESC']);

        return [] === $baskets ? [] : ['orders' => array_map($this->order(...), $baskets)];
    }

    // One basket as the member reads it, amounts in the currency's minor unit as stored
    /** @return array<string, mixed> */
    private function order(Basket $basket): array
    {
        return [
            'number' => $basket->getNumber(),
            'status' => $basket->getStatus(),
            'date' => $basket->getCreation(),
            'total' => $basket->getTotal(),
            'currency' => $basket->getCurrency(),
            'items' => $basket->getItems(),
            'email' => $basket->getEmail(),
            'name' => $basket->getName(),
            'company' => $basket->getCompany(),
            'vatNumber' => $basket->getVatNumber(),
            'address' => $basket->getAddress(),
            'zip' => $basket->getZip(),
            'city' => $basket->getCity(),
            'country' => $basket->getCountry(),
        ];
    }
}
