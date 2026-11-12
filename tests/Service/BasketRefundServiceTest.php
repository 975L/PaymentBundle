<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\RefundableBasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketRefundService;
use c975L\PaymentBundle\Service\GiftCardService;
use c975L\PaymentBundle\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

// Money going back, read the way a paywall reads it: hasPaidFor() on the very order the refund was applied to
class BasketRefundServiceTest extends TestCase
{
    private Basket $basket;
    private GiftCard $card;
    private BasketRepository $repository;

    protected function setUp(): void
    {
        $this->basket = new Basket()
            ->setItems(['product' => [12 => ['quantity' => 1]]])
            ->setTotal(2500)
            ->setNumber('2026-000042')
            ->setStatus(BasketStatus::Paid);
        $this->card = new GiftCard()->setActive(true)->setBalance(1000);
        $this->repository = $this->repository($this->basket);
    }

    // The money handed back in full: the order is refunded, dated, and opens nothing any more
    public function testAFullRefundTakesTheAccessBack(): void
    {
        $this->assertTrue($this->repository->hasPaidFor('buyer@example.org', 'product', 12));

        $this->service()->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
        $this->assertNotNull($this->basket->getRefundedAt());
        $this->assertSame(2500, $this->basket->getRefundedAmount());
        $this->assertFalse($this->repository->hasPaidFor('buyer@example.org', 'product', 12));
    }

    // A shipped order is refunded the same way: having left is no reason to keep what it gave access to
    public function testAShippedOrderIsRefundedToo(): void
    {
        $this->basket->setStatus(BasketStatus::Shipped);

        $this->service()->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // A dispute the shop lost takes the order back whatever the bank took, a chargeback not being a gesture the shop chose
    public function testALostChargebackTakesTheAccessBack(): void
    {
        $this->service()->apply(new PaymentNotification(null, 'stripe', 'pi_1', null, 1000, PaymentEvent::ChargebackLost));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
        $this->assertFalse($this->repository->hasPaidFor('buyer@example.org', 'product', 12));
    }

    // An order charged and never delivered, the money handed back at the provider: settled, and the card it held credited
    public function testAChargedUndeliveredOrderIsRefundedAndItsClaimGivenBack(): void
    {
        $this->basket->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true))->setDiscountClaimed(true);
        $code = $this->createMock(BasketCodeService::class);
        $code->expects($this->once())->method('restore')->willReturn(true);

        $this->service(basketCodeService: $code)->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // Held because the code no longer covered it: nothing was taken off the card, so nothing is credited to it
    public function testAChargedUndeliveredOrderThatClaimedNothingCreditsNothing(): void
    {
        $this->basket->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true))->setDiscountClaimed(false);
        $code = $this->createMock(BasketCodeService::class);
        $code->expects($this->never())->method('restore');

        $this->service(basketCodeService: $code)->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // A gesture on part of the order leaves it paid: recorded for the dashboard, and the access stays
    public function testAPartialRefundKeepsTheAccess(): void
    {
        $this->service()->apply($this->refund(500));

        $this->assertSame(BasketStatus::Paid, $this->basket->getStatus());
        $this->assertSame(500, $this->basket->getRefundedAmount());
        $this->assertNotNull($this->basket->getRefundedAt());
        $this->assertTrue($this->card->isActive());
        $this->assertTrue($this->repository->hasPaidFor('buyer@example.org', 'product', 12));
    }

    // Two partial refunds adding up to the whole order are a full refund, the provider sending the running total
    public function testPartialRefundsReachingTheWholeOrderRefundIt(): void
    {
        $service = $this->service();
        $service->apply($this->refund(500));
        $service->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
        $this->assertFalse($this->repository->hasPaidFor('buyer@example.org', 'product', 12));
    }

    // Providers replay their events: the second one changes nothing - neither the date nor the cards
    public function testAReplayedRefundChangesNothing(): void
    {
        $service = $this->service(expectedFlushes: 1);
        $service->apply($this->refund(2500));
        $refundedAt = $this->basket->getRefundedAt();

        $service->apply($this->refund(2500));

        $this->assertSame($refundedAt, $this->basket->getRefundedAt());
        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // The same partial refund told twice is written once
    public function testAReplayedPartialRefundIsWrittenOnce(): void
    {
        $service = $this->service(expectedFlushes: 1);
        $service->apply($this->refund(500));
        $service->apply($this->refund(500));

        $this->assertSame(500, $this->basket->getRefundedAmount());
    }

    // A card bought by the refunded order is money given back already; its balance is left to say what was on it
    public function testTheGiftCardsTheOrderBoughtAreDeactivated(): void
    {
        $this->service()->apply($this->refund(2500));

        $this->assertFalse($this->card->isActive());
        $this->assertSame(1000, $this->card->getBalance());
    }

    // The part a gift card paid was never charged at the provider: given back to the card once, the replay changing nothing, and never on a partial refund
    public function testTheGiftCardPayingPartOfTheOrderIsCreditedBackOnce(): void
    {
        $this->basket->setDiscountCode('AAAABBBBCCCCDDDD')->setDiscountKind(Basket::CODE_KIND_GIFT_CARD)->setDiscountAmount(1000);
        $basketCodeService = $this->createMock(BasketCodeService::class);
        $basketCodeService->expects($this->once())->method('restore')->with($this->basket)->willReturn(true);

        $service = $this->service(basketCodeService: $basketCodeService);
        $service->apply($this->refund(500));
        $service->apply($this->refund(1500));
        $service->apply($this->refund(1500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // The sale undone, its invoice is cancelled by a credit note - once, the replay changing nothing, and never on a partial refund
    public function testAFullRefundIssuesTheCreditNoteOnce(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())->method('assignCreditNote')->with($this->basket);

        $service = $this->service(invoiceService: $invoiceService);
        $service->apply($this->refund(500));
        $service->apply($this->refund(2500));
        $service->apply($this->refund(2500));
    }

    // A payment the site never recorded is nothing to refund, and nothing is raised for the provider to replay
    public function testAnUnknownPaymentIsDropped(): void
    {
        $this->service(expectedFlushes: 0)->apply(new PaymentNotification(null, 'stripe', 'pi_unknown', null, 2500, PaymentEvent::Refunded));

        $this->assertSame(BasketStatus::Paid, $this->basket->getStatus());
    }

    // A full refund tells each provider that wrote something of its own, with its own lines, once - the replay changing nothing
    public function testAFullRefundTellsTheRefundableProvidersOnce(): void
    {
        $this->basket->setItems(['product' => [12 => ['quantity' => 1]], 'credits' => [3 => ['quantity' => 2]]]);
        $credits = $this->createMock(RefundableBasketItemProviderInterface::class);
        $credits->method('getKind')->willReturn('credits');
        $credits->expects($this->once())->method('onBasketRefunded')->with($this->basket, $this->basket->getItems()['credits']);
        $product = $this->createStub(BasketItemProviderInterface::class);
        $product->method('getKind')->willReturn('product');

        $service = $this->service(providers: [$product, $credits]);
        $service->apply($this->refund(2500));
        $service->apply($this->refund(2500));
    }

    // An order charged and never delivered had its providers' effects rolled back: none is told to take back what it never wrote
    public function testAChargedUndeliveredOrderTellsNoProvider(): void
    {
        $this->basket->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true))->setItems(['credits' => [3 => ['quantity' => 2]]]);
        $credits = $this->createMock(RefundableBasketItemProviderInterface::class);
        $credits->method('getKind')->willReturn('credits');
        $credits->expects($this->never())->method('onBasketRefunded');

        $this->service(providers: [$credits])->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    // Told after the credit note: a provider failing cannot keep the bundle's own steps from running
    public function testTheProvidersAreToldAfterTheCreditNote(): void
    {
        $this->basket->setItems(['credits' => [3 => ['quantity' => 2]]]);
        $calls = [];
        $invoice = $this->createStub(InvoiceService::class);
        $invoice->method('assignCreditNote')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'credit_note';
        });
        $credits = $this->createStub(RefundableBasketItemProviderInterface::class);
        $credits->method('getKind')->willReturn('credits');
        $credits->method('onBasketRefunded')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'provider';
        });

        $this->service(invoiceService: $invoice, providers: [$credits])->apply($this->refund(2500));

        $this->assertSame(['credit_note', 'provider'], $calls);
    }

    // A partial refund leaves the order paid: no provider is told to take anything back
    public function testAPartialRefundTellsNoProvider(): void
    {
        $credits = $this->createMock(RefundableBasketItemProviderInterface::class);
        $credits->method('getKind')->willReturn('product');
        $credits->expects($this->never())->method('onBasketRefunded');

        $this->service(providers: [$credits])->apply($this->refund(500));
    }

    // One provider failing is logged and the next one is still told, the refund itself standing
    public function testAFailingProviderDoesNotStopTheOthers(): void
    {
        $this->basket->setItems(['product' => [12 => ['quantity' => 1]], 'credits' => [3 => ['quantity' => 2]]]);
        $failing = $this->createStub(RefundableBasketItemProviderInterface::class);
        $failing->method('getKind')->willReturn('product');
        $failing->method('onBasketRefunded')->willThrowException(new \RuntimeException('down'));
        $credits = $this->createMock(RefundableBasketItemProviderInterface::class);
        $credits->method('getKind')->willReturn('credits');
        $credits->expects($this->once())->method('onBasketRefunded');

        $this->service(providers: [$failing, $credits])->apply($this->refund(2500));

        $this->assertSame(BasketStatus::Refunded, $this->basket->getStatus());
    }

    private function refund(int $amount): PaymentNotification
    {
        return new PaymentNotification(null, 'stripe', 'pi_1', null, $amount, PaymentEvent::Refunded);
    }

    private function service(?int $expectedFlushes = null, ?BasketCodeService $basketCodeService = null, ?InvoiceService $invoiceService = null, array $providers = []): BasketRefundService
    {
        $payment = $this->basket->getPayment() ?? new Payment();
        $payment->setBasket($this->basket);

        $payments = $this->createStub(EntityRepository::class);
        $payments->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?Payment => ['gateway' => 'stripe', 'transactionId' => 'pi_1'] === $criteria ? $payment : null
        );

        $entityManager = null === $expectedFlushes ? $this->createStub(EntityManagerInterface::class) : $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($payments);
        if (null !== $expectedFlushes) {
            $entityManager->expects($this->exactly($expectedFlushes))->method('flush');
        }

        $giftCardService = $this->createStub(GiftCardService::class);
        $giftCardService->method('findIssuedBy')->willReturn([$this->card]);

        if (null === $basketCodeService) {
            $basketCodeService = $this->createStub(BasketCodeService::class);
            $basketCodeService->method('restore')->willReturn(true);
        }

        return new BasketRefundService($this->repository, $basketCodeService, $giftCardService, $invoiceService ?? $this->createStub(InvoiceService::class), $entityManager, new NullLogger(), new BasketItemProviderRegistry($providers));
    }

    // The database stood in for: the claim moves only a settled order, which is what makes a replay harmless, and the finders return it while it is settled, as their queries do
    private function repository(Basket $basket): BasketRepository
    {
        return new class ($basket) extends BasketRepository {
            public function __construct(private readonly Basket $basket)
            {
            }

            public function claimRefunded(Basket $basket, int $amount): bool
            {
                return $basket->getStatus()->isSettled() || $basket->isChargedUndelivered();
            }

            public function findPaidByUser(UserInterface $user, ?int $limit = null): array
            {
                return $this->basket->getStatus()->isSettled() ? [$this->basket] : [];
            }

            public function findPaidByEmail(string $email): array
            {
                return $this->basket->getStatus()->isSettled() ? [$this->basket] : [];
            }
        };
    }
}
