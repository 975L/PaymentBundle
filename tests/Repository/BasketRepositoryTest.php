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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

// The orders read by address rather than by account, which is what a review is checked against (see ShopBundle's ProductReviewVerifier)
class BasketRepositoryTest extends TestCase
{
    private string $dql = '';

    /**
     * @var array<string, mixed>
     */
    private array $parameters = [];

    // An order that never completed is not a purchase: showing one as such would have a review vouched for by a checkout the visitor walked out of
    public function testFindPaidByEmailReadsThePaidAndShippedOrdersAlone(): void
    {
        $this->createRepository()->findPaidByEmail('marie@example.org');

        $this->assertStringContainsString('b.status IN (:statuses)', $this->dql);
        $this->assertSame(['paid', 'shipped'], $this->parameters['statuses']);
    }

    // An address typed with a capital at the checkout and without one on a review form is the same address, and the comparison says so on both sides
    public function testFindPaidByEmailComparesTheAddressInLowerCaseOnBothSides(): void
    {
        $this->createRepository()->findPaidByEmail('  Marie@Example.ORG  ');

        $this->assertStringContainsString('LOWER(b.email) = :email', $this->dql);
        $this->assertSame('marie@example.org', $this->parameters['email']);
    }

    // Newest first, like the orders the customer area lists
    public function testFindPaidByEmailReturnsTheNewestOrdersFirst(): void
    {
        $this->createRepository()->findPaidByEmail('marie@example.org');

        $this->assertStringContainsString('ORDER BY b.creation DESC', $this->dql);
    }

    // No address, no order: a query on an empty string would read every order placed without one
    public function testAnEmptyAddressRunsNoQueryAtAll(): void
    {
        $this->assertSame([], $this->createRepository()->findPaidByEmail('   '));
        $this->assertSame('', $this->dql);
    }

    /**
     * The two conditions that keep an order nobody asked to be reminded of out of the reminders.
     *
     * Read here rather than trusted to the defaults that happen to satisfy them: an order written in the
     * back-office - a payment link - carries neither an address nor a consent, and a query dropping either of
     * these clauses would start writing to people who never gave one.
     */
    public function testOnlyTheOrdersWhoseCustomerAskedToBeRemindedAreEverReminded(): void
    {
        $this->createRepository()->findToRemind(1, 0);

        $this->assertStringContainsString('b.reminderConsent = true', $this->dql);
        $this->assertStringContainsString('b.email IS NOT NULL', $this->dql);
        $this->assertSame('validated', $this->parameters['status']);
    }

    // The query the repository builds is read back through the DQL the entity manager is handed, the rest of it being Doctrine's own
    private function createRepository(): BasketRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [Basket::class, new ClassMetadata(Basket::class)],
        ]);
        $entityManager->method('createQueryBuilder')->willReturnCallback(fn (): QueryBuilder => new QueryBuilder($entityManager));
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->dql = $dql;

            $query = $this->createStub(Query::class);
            $query->method('setParameters')->willReturnCallback(function (mixed $parameters) use (&$query): Query {
                foreach ($parameters as $parameter) {
                    $this->parameters[$parameter->getName()] = $parameter->getValue();
                }

                return $query;
            });
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnSelf();
            $query->method('getResult')->willReturn([]);

            return $query;
        });

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnMap([
            [Basket::class, $entityManager],
        ]);

        return new BasketRepository($registry);
    }
}
