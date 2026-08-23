<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Repository;

use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Repository\DiscountRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

// The one statement that decides whether a promotional code may still be spent, read back through the SQL it hands the database
class DiscountRepositoryTest extends TestCase
{
    private string $sql = '';

    /**
     * @var array<string, mixed>
     */
    private array $parameters = [];

    // Two orders settling at the same second both read the same count: the quota is a condition of the update itself, so the database is what refuses the second
    public function testTheQuotaIsTheConditionOfTheCountItRaises(): void
    {
        $this->createRepository()->claimUse(new Discount());

        $this->assertStringContainsString('used_count = used_count + 1', $this->sql);
        $this->assertStringContainsString('max_uses = 0 OR used_count < max_uses', $this->sql);
    }

    // A code turned off between the moment the basket was priced and the moment it is paid must not be counted as used
    public function testACodeSwitchedOffIsNeverCounted(): void
    {
        $this->createRepository()->claimUse(new Discount());

        $this->assertStringContainsString('active = 1', $this->sql);
    }

    // Same for a code that has not started yet or has run out: both dates are read against the moment of the payment, not against the moment the basket was made
    public function testACodeOutsideItsValidityIsNeverCounted(): void
    {
        $this->createRepository()->claimUse(new Discount());

        $this->assertStringContainsString('valid_from IS NULL OR valid_from <= :now', $this->sql);
        $this->assertStringContainsString('valid_until IS NULL OR valid_until >= :now', $this->sql);
        $this->assertArrayHasKey('now', $this->parameters);
    }

    // What the caller acts on: the row was updated or it was not, and nothing else is read back to find out
    public function testTheAnswerIsWhetherTheDatabaseTookIt(): void
    {
        $this->assertTrue($this->createRepository(1)->claimUse(new Discount()));
        $this->assertFalse($this->createRepository(0)->claimUse(new Discount()));
    }

    // The statement the repository runs is caught on the connection, the rest being Doctrine's own
    private function createRepository(int $affectedRows = 1): DiscountRepository
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $parameters = []) use ($affectedRows): int {
            $this->sql = $sql;
            $this->parameters = $parameters;

            return $affectedRows;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [Discount::class, new ClassMetadata(Discount::class)],
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnMap([
            [Discount::class, $entityManager],
        ]);

        return new DiscountRepository($registry);
    }
}
