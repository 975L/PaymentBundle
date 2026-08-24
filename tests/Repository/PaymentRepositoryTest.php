<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Repository;

use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

// The charge nobody was told about, read off the payments rather than off the orders (see BasketIntegrityHealthCheckProvider)
class PaymentRepositoryTest extends TestCase
{
    private string $dql = '';

    /**
     * @var array<string, mixed>
     */
    private array $parameters = [];

    /**
     * A payment settling a test order is not a charge nobody was told about.
     *
     * The order is left where it is on purpose, and reporting it would have a shopkeeper trying their own
     * checkout out fill the dashboard with money nobody ever took.
     */
    public function testAPaymentSettlingATestOrderIsLeftOut(): void
    {
        $this->createRepository()->findFinishedWithoutDeliveredBasket(new \DateTime('-12 months'), new \DateTime('-60 minutes'));

        $this->assertStringContainsString('b.id IS NULL OR b.testMode = false', $this->dql);
    }

    // A payment carrying no order at all has no test flag to be read off, and is the very case this check exists for
    public function testAPaymentWithNoOrderAtAllIsStillReported(): void
    {
        $this->createRepository()->findFinishedWithoutDeliveredBasket(new \DateTime('-12 months'), new \DateTime('-60 minutes'));

        $this->assertStringContainsString('b.id IS NULL OR b.status NOT IN (:delivered)', $this->dql);
        $this->assertStringContainsString('p.isFinished = true', $this->dql);
        $this->assertSame(['paid', 'shipped'], $this->parameters['delivered']);
    }

    // The query the repository builds is read back through the DQL the entity manager is handed, the rest of it being Doctrine's own
    private function createRepository(): PaymentRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [Payment::class, new ClassMetadata(Payment::class)],
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
            [Payment::class, $entityManager],
        ]);

        return new PaymentRepository($registry);
    }
}
