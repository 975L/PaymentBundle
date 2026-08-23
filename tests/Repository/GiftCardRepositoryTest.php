<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Repository;

use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

// The one statement that takes money off a card, read back through the SQL it hands the database
class GiftCardRepositoryTest extends TestCase
{
    private string $sql = '';

    /**
     * @var array<string, mixed>
     */
    private array $parameters = [];

    // Two orders settling at the same second both read the same balance: the balance is a condition of its own deduction, so the database is what refuses the second
    public function testTheBalanceIsTheConditionOfItsOwnDeduction(): void
    {
        $this->createRepository()->claimAmount(new GiftCard(), 1500);

        $this->assertStringContainsString('balance = balance - :amount', $this->sql);
        $this->assertStringContainsString('balance >= :amount', $this->sql);
        $this->assertSame(1500, $this->parameters['amount']);
    }

    // A card reported stolen or run out of validity between the pricing of the basket and its payment must not be drained
    public function testACardSwitchedOffOrExpiredIsNeverDrained(): void
    {
        $this->createRepository()->claimAmount(new GiftCard(), 1500);

        $this->assertStringContainsString('active = 1', $this->sql);
        $this->assertStringContainsString('valid_until IS NULL OR valid_until >= :now', $this->sql);
        $this->assertArrayHasKey('now', $this->parameters);
    }

    // An order a card pays nothing of asks the database nothing: a statement taking off zero would answer "no row" and read as a refusal
    public function testNothingIsClaimedForNothing(): void
    {
        $this->assertTrue($this->createRepository(0)->claimAmount(new GiftCard(), 0));
        $this->assertSame('', $this->sql);
    }

    // What the caller acts on: the row was updated or it was not, and nothing else is read back to find out
    public function testTheAnswerIsWhetherTheDatabaseTookIt(): void
    {
        $this->assertTrue($this->createRepository(1)->claimAmount(new GiftCard(), 1500));
        $this->assertFalse($this->createRepository(0)->claimAmount(new GiftCard(), 1500));
    }

    // The statement the repository runs is caught on the connection, the rest being Doctrine's own
    private function createRepository(int $affectedRows = 1): GiftCardRepository
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
            [GiftCard::class, new ClassMetadata(GiftCard::class)],
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnMap([
            [GiftCard::class, $entityManager],
        ]);

        return new GiftCardRepository($registry);
    }
}
