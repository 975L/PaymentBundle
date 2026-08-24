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
     * The three conditions that keep an order out of the reminders.
     *
     * Read here rather than trusted to the defaults that happen to satisfy them: a query dropping the
     * opposition would write to somebody who asked to hear no more, and one dropping the service flag would
     * start dunning the clients a shopkeeper wrote a payment link for and is chasing themselves.
     */
    public function testTheOppositionTheAddressAndThePaymentLinksAllGateTheReminders(): void
    {
        $this->createRepository()->findToRemind(1, 0);

        $this->assertStringContainsString('b.reminderOptOutAt IS NULL', $this->dql);
        $this->assertStringContainsString('b.contentflags != :service', $this->dql);
        $this->assertStringContainsString('b.email IS NOT NULL', $this->dql);
        $this->assertSame('validated', $this->parameters['status']);
        $this->assertSame(Basket::CONTENT_FLAG_SERVICE, $this->parameters['service']);
    }

    // An order with nothing to pay carries no payment row at all (see BasketService::paid()), so reading it as unpaid would report every free order there ever was
    public function testTheIntegrityQueryLeavesTheOrdersWithNothingToPayOut(): void
    {
        $this->createRepository()->findDeliveredWithoutFinishedPayment(new \DateTime('-12 months'));

        $this->assertStringContainsString('b.total + b.shipping - b.discountAmount > 0', $this->dql);
        $this->assertStringContainsString('p.id IS NULL OR p.isFinished = false', $this->dql);
        $this->assertSame(['paid', 'shipped'], $this->parameters['delivered']);
    }

    // "eur" and "EUR" are one and the same currency, and a check reporting them as two would report every order of a shop storing them differently on each side
    public function testTheAmountQueryComparesTheCurrencyWhateverItsCase(): void
    {
        $this->createRepository()->findWithPaymentAmountMismatch(new \DateTime('-12 months'));

        $this->assertStringContainsString('LOWER(p.currency) <> LOWER(b.currency)', $this->dql);
        $this->assertStringContainsString('p.isFinished = true', $this->dql);
    }

    // Payable is what a customer can still be charged for: an archived order is out of current business and no longer one of them
    public function testThePayableQueryReadsTheOpenBasketsAndLeavesTheArchivedOut(): void
    {
        $this->createRepository()->findPayable();

        $this->assertStringContainsString('b.archived IS NULL', $this->dql);
        $this->assertSame(['new', 'validated'], $this->parameters['open']);
    }

    /**
     * Every query behind the integrity check leaves the test orders out.
     *
     * A shop trying its checkout out writes orders nobody settles, delivers or invoices, and each one of them
     * would be reported as a defect - the dashboard filling up with the shopkeeper's own trials until the one
     * order that is genuinely wrong is no longer visible among them.
     */
    public function testEveryIntegrityQueryLeavesTheTestOrdersOut(): void
    {
        $since = new \DateTime('-12 months');
        $queries = [
            fn () => $this->createRepository()->findDeliveredWithoutFinishedPayment($since),
            fn () => $this->createRepository()->findWithPaymentAmountMismatch($since),
            fn () => $this->createRepository()->findDeliveredWithoutNumber($since),
            fn () => $this->createRepository()->findOrdersSince($since),
            fn () => $this->createRepository()->findPayable(),
        ];

        foreach ($queries as $query) {
            $this->dql = '';
            $query();

            $this->assertStringContainsString('b.testMode = false', $this->dql);
        }
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
