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
use c975L\PaymentBundle\Management\AccountSectionProvider;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;

class AccountSectionProviderTest extends TestCase
{
    // One section, the member's latest orders only, read in this bundle's own domain
    public function testListsTheLatestOrdersOfTheMember(): void
    {
        $user = $this->createStub(UserInterface::class);
        $baskets = [new Basket()];

        $repository = $this->createMock(BasketRepository::class);
        $repository->expects($this->once())->method('findPaidByUser')->with($user, 3)->willReturn($baskets);

        $sections = new AccountSectionProvider($repository)->getAccountSections($user);

        $this->assertCount(1, $sections);
        $this->assertSame('label.my_orders', $sections[0]['title']);
        $this->assertSame('payment', $sections[0]['translation_domain']);
        $this->assertSame('@c975LPayment/customer/_account_section.html.twig', $sections[0]['template']);
        $this->assertSame(['baskets' => $baskets], $sections[0]['context']);
    }
}
