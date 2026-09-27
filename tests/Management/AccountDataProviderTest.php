<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Management\AccountDataProvider;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;

class AccountDataProviderTest extends TestCase
{
    // Each basket of the account, with what it holds and where it went
    public function testExportsTheBasketsOfTheAccount(): void
    {
        $basket = new Basket()->setNumber('2026-000123')->setStatus('paid')->setCurrency('EUR')->setName('Laurent')->setCity('Annecy');
        $basket->setTotal(4500);

        $data = $this->provider([$basket])->getAccountData($this->createStub(UserInterface::class));

        $this->assertSame('2026-000123', $data['orders'][0]['number']);
        $this->assertSame(4500, $data['orders'][0]['total']);
        $this->assertSame('Annecy', $data['orders'][0]['city']);
    }

    // No basket, no "orders" key: the export says nothing it does not hold
    public function testNothingWithoutBaskets(): void
    {
        $this->assertSame([], $this->provider([])->getAccountData($this->createStub(UserInterface::class)));
    }

    /** @param list<Basket> $baskets */
    private function provider(array $baskets): AccountDataProvider
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findBy')->willReturn($baskets);

        return new AccountDataProvider($repository);
    }
}
