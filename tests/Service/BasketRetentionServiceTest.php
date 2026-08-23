<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketRetentionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * How long a basket lives, and what going away means.
 *
 * The durations themselves are read off the constants rather than asserted: they answer to the law and not to
 * this suite, and a test restating "ten years" would only say twice what one change would have to correct twice.
 * What is worth holding is the shape of each pass - what it takes, what it leaves, and what leaves with it.
 */
class BasketRetentionServiceTest extends TestCase
{
    /** @var object[] */
    private array $removed = [];

    // A payment whose basket has gone is a row nothing points to any more: the relation carries no cascade, so the service has to do it itself
    public function testDeletingAnAbandonedOrderTakesItsPaymentWithIt(): void
    {
        $payment = new Payment();
        $basket = new Basket()->setPayment($payment);

        $this->service(['findAbandoned' => [$basket]])->deleteAbandoned();

        $this->assertContains($basket, $this->removed);
        $this->assertContains($payment, $this->removed);
        $this->assertNull($basket->getPayment(), 'The link is cut before the two rows go, so neither holds the other back');
    }

    // A basket nobody ever paid carries no payment at all, and the pass must not invent one to remove
    public function testABasketWithoutAPaymentRemovesOnlyItself(): void
    {
        $basket = new Basket();

        $this->service(['findUnvalidated' => [$basket]])->deleteUnvalidated();

        $this->assertSame([$basket], $this->removed);
    }

    // Archiving is a date and not a status: the checkout reads the status as a string in half a dozen places, and an order that stopped answering to "shipped" would stop being recognised as the delivered one it still is
    public function testArchivingStampsADateAndLeavesTheStatusAlone(): void
    {
        $basket = new Basket()->setStatus('shipped');

        $count = $this->service(['findToArchive' => [$basket]])->archiveDelivered();

        $this->assertSame(1, $count);
        $this->assertInstanceOf(\DateTimeInterface::class, $basket->getArchived());
        $this->assertSame('shipped', $basket->getStatus());
        $this->assertSame([], $this->removed, 'An archived order is kept, not deleted');
    }

    // "Taken and never confirmed" leaves the very same row behind as "never paid" - a lost webhook, a notification whose amount did not match - so the figures are written to the log before the only local trace of them goes
    public function testAnAbandonedOrderIsLoggedBeforeItGoes(): void
    {
        $payment = new Payment()->setGateway('stripe')->setGatewayReference('cs_test_42');
        $basket = new Basket()->setNumber('2026-000123')->setCurrency('EUR')->setPayment($payment);
        $basket->setTotal(4500);

        $records = [];
        $this->service(['findAbandoned' => [$basket]], $records)->deleteAbandoned();

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('2026-000123', $records[0]['context']['number']);
        $this->assertSame('cs_test_42', $records[0]['context']['gatewayReference']);
        $this->assertArrayNotHasKey('email', $records[0]['context'], 'The purge stays the purge: no personal data survives in the log');
    }

    // Only the abandoned pass logs: a basket nobody ever validated carries no money, and an order whose ten years are up went through the books long ago
    public function testTheOtherPassesLogNothing(): void
    {
        $records = [];
        $this->service(['findUnvalidated' => [new Basket()], 'findExpired' => [new Basket()]], $records)->run();

        $this->assertSame([], $records);
    }

    // The nightly pass says what it did, step by step: a purge nobody can read is one nobody notices going wrong
    public function testTheNightlyPassReportsEveryStep(): void
    {
        $counts = $this->service([
            'findUnvalidated' => [new Basket()],
            'findAbandoned' => [new Basket(), new Basket()],
            'findToArchive' => [new Basket()],
            'findExpired' => [],
        ])->run();

        $this->assertSame(['unvalidated' => 1, 'abandoned' => 2, 'archived' => 1, 'expired' => 0], $counts);
    }

    /**
     * @param array<string, Basket[]>                                        $found   what each query hands back
     * @param list<array{level: string, context: array<string, mixed>}>|null $records collected when passed, the service getting a NullLogger otherwise
     */
    private function service(array $found, ?array &$records = null): BasketRetentionService
    {
        $repository = $this->createStub(BasketRepository::class);
        foreach (['findUnvalidated', 'findAbandoned', 'findToArchive', 'findExpired'] as $method) {
            $repository->method($method)->willReturn($found[$method] ?? []);
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        if (null === $records) {
            return new BasketRetentionService($repository, $entityManager, new NullLogger());
        }

        $logger = new class ($records) extends AbstractLogger {
            /**
             * @param list<array{level: string, context: array<string, mixed>}> $records
             */
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable | string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };

        return new BasketRetentionService($repository, $entityManager, $logger);
    }
}
