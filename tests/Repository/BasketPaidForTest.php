<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Repository;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

// What a paywall asks before showing what it protects: has this buyer paid for this item
class BasketPaidForTest extends TestCase
{
    public function testHoldsItemFindsAnItemUnderItsOwnKind(): void
    {
        $basket = $this->basketHolding(['product' => [12 => ['quantity' => 1]]]);

        $this->assertTrue($basket->holdsItem('product', 12));
    }

    // The items come back from a JSON column, where the id was a string
    public function testHoldsItemMatchesAnIdWhateverSideItWasTypedOn(): void
    {
        $basket = $this->basketHolding(['product' => ['12' => ['quantity' => 1]]]);

        $this->assertTrue($basket->holdsItem('product', 12));
        $this->assertTrue($basket->holdsItem('product', '12'));
    }

    // Two satellites number their own items from 1: the kind is half the answer, never a detail
    public function testHoldsItemRefusesTheSameIdUnderAnotherKind(): void
    {
        $basket = $this->basketHolding(['product' => [12 => ['quantity' => 1]]]);

        $this->assertFalse($basket->holdsItem('crowdfunding', 12));
        $this->assertFalse($basket->holdsItem('product', 13));
    }

    public function testHoldsItemOnAnEmptyBasket(): void
    {
        $this->assertFalse(new Basket()->holdsItem('product', 12));
    }

    public function testHasPaidForReadsTheOrdersOfAnAccount(): void
    {
        $user = $this->createStub(UserInterface::class);
        $repository = $this->repositoryReturning(
            [$this->basketHolding(['product' => [7 => []]]), $this->basketHolding(['product' => [12 => []]])],
            []
        );

        $this->assertTrue($repository->hasPaidFor($user, 'product', 12));
        $this->assertFalse($repository->hasPaidFor($user, 'product', 99));
    }

    // A visitor who never opened an account has bought all the same, and the address is all their order carries
    public function testHasPaidForReadsTheOrdersOfAnAddress(): void
    {
        $repository = $this->repositoryReturning([], [$this->basketHolding(['gallery' => [3 => []]])]);

        $this->assertTrue($repository->hasPaidFor('buyer@example.org', 'gallery', 3));
        $this->assertFalse($repository->hasPaidFor('buyer@example.org', 'gallery', 4));
    }

    public function testHasPaidForOnABuyerWithNoOrderAtAll(): void
    {
        $repository = $this->repositoryReturning([], []);

        $this->assertFalse($repository->hasPaidFor('buyer@example.org', 'gallery', 3));
    }

    /**
     * @param array<string, array<int|string, mixed>> $items
     */
    private function basketHolding(array $items): Basket
    {
        return new Basket()->setItems($items);
    }

    /**
     * The two finders are all this method has of the database, and each is a query of its own.
     *
     * @param list<Basket> $byUser
     * @param list<Basket> $byEmail
     */
    private function repositoryReturning(array $byUser, array $byEmail): BasketRepository
    {
        return new class ($byUser, $byEmail) extends BasketRepository {
            /**
             * @param list<Basket> $byUser
             * @param list<Basket> $byEmail
             */
            public function __construct(
                private readonly array $byUser,
                private readonly array $byEmail,
            ) {
            }

            public function findPaidByUser(UserInterface $user): array
            {
                return $this->byUser;
            }

            public function findPaidByEmail(string $email): array
            {
                return $this->byEmail;
            }
        };
    }
}
