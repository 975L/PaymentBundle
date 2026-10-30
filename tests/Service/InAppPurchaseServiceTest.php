<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Contract\InAppProduct;
use c975L\PaymentBundle\Contract\InAppProductProviderInterface;
use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Entity\StorePurchase;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\InAppProductProviderRegistry;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Repository\StorePurchaseRepository;
use c975L\PaymentBundle\Service\InAppPurchaseService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

// The path of a store purchase: who it is delivered to, how often, and what a refund takes back
class InAppPurchaseServiceTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];
    private ?StorePurchase $recorded = null;
    private ?InAppTransaction $answer = null;
    private bool $acknowledgeFails = false;

    // The same holder always buys under the same token, a UUID the stores accept, and two holders never share one
    public function testTheAccountTokenIsAStableUuidPerHolder(): void
    {
        $service = $this->service();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $service->accountToken('u1'));
        $this->assertSame($service->accountToken('u1'), $service->accountToken('u1'));
        $this->assertNotSame($service->accountToken('u1'), $service->accountToken('u2'));
    }

    public function testAVerifiedPurchaseIsDeliveredAcknowledgedAndRecorded(): void
    {
        $service = $this->service();
        $this->answer = $this->transaction($service->accountToken('u1'));

        $this->assertTrue($service->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertSame(['deliver u1 back.a apple', 'acknowledge 1'], $this->log);
        $this->assertSame(['u1', 'back.a', 'sandbox'], [$this->recorded?->getHolder(), $this->recorded->getProductId(), $this->recorded->getEnvironment()]);
    }

    // A proof copied from someone else's phone carries their token, not the caller's
    public function testAPurchaseMadeUnderAnotherAccountIsRefused(): void
    {
        $service = $this->service();
        $this->answer = $this->transaction($service->accountToken('u2'));

        $this->expectException(InvalidNotificationException::class);
        $service->verify('apple', 'u1', ['transactionId' => '1']);
    }

    // A restore runs the same path: the holder gets it again, the row is not written twice
    public function testARestoreDeliversAgainWithoutRecordingTwice(): void
    {
        $service = $this->service(new StorePurchase('apple', '1', 'back.a', 'u1'));
        $this->answer = $this->transaction($service->accountToken('u1'));

        $this->assertTrue($service->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertContains('deliver u1 back.a apple', $this->log);
        $this->assertNull($this->recorded);
    }

    public function testARefundedPurchaseIsNotDeliveredAgain(): void
    {
        $service = $this->service(new StorePurchase('apple', '1', 'back.a', 'u1')->markRefunded());
        $this->answer = $this->transaction($service->accountToken('u1'), PaymentEvent::Refunded);

        $this->assertFalse($service->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertSame([], $this->log);
    }

    // The store calling paid a purchase refunded here means the refund was reversed: the holder gets it back on restoring
    public function testARefundReversedIsDeliveredAgainOnARestore(): void
    {
        $purchase = new StorePurchase('apple', '1', 'back.a', 'u1')->markRefunded();
        $service = $this->service($purchase);
        $this->answer = $this->transaction($service->accountToken('u1'));

        $this->assertTrue($service->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertFalse($purchase->isRefunded());
        $this->assertContains('deliver u1 back.a apple', $this->log);
    }

    // Recorded before delivering: a failing acknowledgement leaves a row a retry or a refund finds back
    public function testAPurchaseIsRecordedEvenWhenTheAcknowledgementFails(): void
    {
        $service = $this->service();
        $this->answer = $this->transaction($service->accountToken('u1'));
        $this->acknowledgeFails = true;

        try {
            $service->verify('apple', 'u1', ['transactionId' => '1']);
            $this->fail('The acknowledgement failure was swallowed');
        } catch (PaymentUnavailableException) {
            $this->assertSame('u1', $this->recorded?->getHolder());
        }
    }

    // Two requests verifying the same purchase at once: the one losing the race delivers nothing and still answers yes
    public function testAPurchaseRecordedMeanwhileIsNotDeliveredTwice(): void
    {
        $service = $this->service(flushFails: true);
        $this->answer = $this->transaction($service->accountToken('u1'));

        $this->assertTrue($service->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertSame([], $this->log);
    }

    public function testAPendingPurchaseGivesNothingYet(): void
    {
        $this->assertFalse($this->service()->verify('apple', 'u1', ['transactionId' => '1']));
        $this->assertSame([], $this->log);
    }

    public function testAnUnknownStoreCannotVerify(): void
    {
        $this->expectException(PaymentUnavailableException::class);

        $this->service()->verify('amazon', 'u1', []);
    }

    // The refund names the transaction only; the holder and the product are the recorded ones
    public function testARefundRevokesWhatWasDelivered(): void
    {
        $purchase = new StorePurchase('google', 'GPA.1', 'back.a', 'u1');
        $this->service($purchase)->applyNotification(new InAppTransaction('google', '', 'GPA.1', event: PaymentEvent::Refunded));

        $this->assertTrue($purchase->isRefunded());
        $this->assertSame(['revoke u1 back.a google'], $this->log);
    }

    public function testARefundOfAnUnknownTransactionChangesNothing(): void
    {
        $this->service()->applyNotification(new InAppTransaction('google', '', 'GPA.9', event: PaymentEvent::Refunded));

        $this->assertSame([], $this->log);
    }

    public function testARefundReversedByTheStoreGivesBackWhatItTook(): void
    {
        $purchase = new StorePurchase('apple', '1', 'back.a', 'u1')->markRefunded();
        $this->service($purchase)->applyNotification(new InAppTransaction('apple', 'back.a', '1'));

        $this->assertFalse($purchase->isRefunded());
        $this->assertSame(['deliver u1 back.a apple'], $this->log);
    }

    public function testAPaidNotificationOfAPurchaseNeverRefundedChangesNothing(): void
    {
        $this->service(new StorePurchase('apple', '1', 'back.a', 'u1'))->applyNotification(new InAppTransaction('apple', 'back.a', '1'));

        $this->assertSame([], $this->log);
    }

    private function transaction(string $accountToken, PaymentEvent $event = PaymentEvent::Paid): InAppTransaction
    {
        return new InAppTransaction('apple', 'back.a', '1', $accountToken, 'sandbox', $event);
    }

    private function service(?StorePurchase $existing = null, bool $flushFails = false): InAppPurchaseService
    {
        $repository = $this->createStub(StorePurchaseRepository::class);
        $repository->method('findOneByTransaction')->willReturn($existing);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->recorded = $entity instanceof StorePurchase ? $entity : null;
        });
        if ($flushFails) {
            $entityManager->method('flush')->willThrowException($this->createStub(UniqueConstraintViolationException::class));
        }

        return new InAppPurchaseService(new InAppStoreRegistry([$this->store('apple'), $this->store('google')]), new InAppProductProviderRegistry([$this->provider()]), $repository, $entityManager, 'secret');
    }

    private function store(string $slug): InAppStoreInterface
    {
        $test = $this;

        return new readonly class ($slug, $test) implements InAppStoreInterface {
            public function __construct(private string $slug, private InAppPurchaseServiceTest $test)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function verifyPurchase(array $proof): ?InAppTransaction
            {
                return $this->test->answer();
            }

            public function acknowledge(InAppTransaction $transaction): void
            {
                $this->test->acknowledgeFails() && throw new PaymentUnavailableException('Google cannot be reached');
                $this->test->log('acknowledge ' . $transaction->transactionId);
            }

            public function readNotification(Request $request): ?InAppTransaction
            {
                return null;
            }

            public function canPublish(): bool
            {
                return false;
            }

            public function publishProduct(InAppProduct $product): void
            {
            }
        };
    }

    private function provider(): InAppProductProviderInterface
    {
        $test = $this;

        return new readonly class ($test) implements InAppProductProviderInterface {
            public function __construct(private InAppPurchaseServiceTest $test)
            {
            }

            public function getProducts(): iterable
            {
                return [];
            }

            public function supports(string $productId): bool
            {
                return str_starts_with($productId, 'back.');
            }

            public function deliver(string $holder, string $productId, string $store): void
            {
                $this->test->log('deliver ' . $holder . ' ' . $productId . ' ' . $store);
            }

            public function revoke(string $holder, string $productId, string $store): void
            {
                $this->test->log('revoke ' . $holder . ' ' . $productId . ' ' . $store);
            }
        };
    }

    public function answer(): ?InAppTransaction
    {
        return $this->answer;
    }

    public function acknowledgeFails(): bool
    {
        return $this->acknowledgeFails;
    }

    public function log(string $line): void
    {
        $this->log[] = $line;
    }
}
