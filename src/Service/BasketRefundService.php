<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

// Money going back to the customer, as a provider reports it: the order stays the one record of the sale, and its status alone says whether it still hands anything over - no right is kept beside it to take away
class BasketRefundService
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketCodeService $basketCodeService,
        private readonly GiftCardService $giftCardService,
        private readonly InvoiceService $invoiceService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    // Refunds the order in full, or records a partial refund and leaves the order paid. Every outcome nobody can act on is logged and dropped rather than raised: the provider would replay it for days
    public function apply(PaymentNotification $notification): void
    {
        $basket = $this->findBasket($notification);
        if (null === $basket) {
            $this->logger->error('Refund notification names no known payment', ['gateway' => $notification->gateway, 'transaction' => $notification->transactionId]);

            return;
        }

        // Compared to what the customer was charged, not to the order total: a discount or a gift card paid part of it, and the provider can only ever hand back what it took. A lost chargeback takes the order back whatever the amount
        $amount = $notification->amount ?? $basket->getPayable();
        if (PaymentEvent::ChargebackLost !== $notification->event && $amount < $basket->getPayable()) {
            $this->partial($basket, $amount);

            return;
        }

        if (!$this->basketRepository->claimRefunded($basket, $amount)) {
            $this->logger->info('Refund notification for an order not settled, or refunded already', ['basket' => $basket->getId(), 'status' => $basket->getStatus()->value]);

            return;
        }

        // The claim went round the unit of work, which still holds the order as it was read
        $basket
            ->setStatus(BasketStatus::Refunded)
            ->setRefundedAmount($amount)
            ->setRefundedAt(new \DateTime());

        $this->logger->warning('Order {number} refunded: what it sold is no longer handed over', ['number' => $basket->getNumber(), 'event' => $notification->event->value, 'amount' => $amount]);
        $this->deactivateGiftCards($basket);
        $this->restoreGiftCard($basket);
        // The invoice cancelled by a document of its own, the sale having been undone
        $this->invoiceService->assignCreditNote($basket);
    }

    // The payment the provider names, which is what the checkout recorded as its transaction - the basket id never travels on a charge or a dispute
    private function findBasket(PaymentNotification $notification): ?Basket
    {
        if (null === $notification->transactionId) {
            return null;
        }

        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $notification->gateway, 'transactionId' => $notification->transactionId]);

        return $payment?->getBasket();
    }

    // Kept on the order for the dashboard to tell the shopkeeper, the order itself staying paid. Only a larger total is written, so a replayed event changes nothing
    private function partial(Basket $basket, int $amount): void
    {
        if (!$basket->getStatus()->isSettled() || $amount <= $basket->getRefundedAmount()) {
            $this->logger->info('Partial refund notification already recorded, or for an order no longer settled', ['basket' => $basket->getId(), 'amount' => $amount]);

            return;
        }

        $basket
            ->setRefundedAmount($amount)
            ->setRefundedAt(new \DateTime())
            ->setModification(new \DateTime());
        $this->entityManager->flush();

        $this->logger->warning('Order {number} refunded in part ({amount} of {payable}): it still hands over what it sold', ['number' => $basket->getNumber(), 'amount' => $amount, 'payable' => $basket->getPayable()]);
    }

    // The provider hands back what it charged, and the part a gift card paid was never charged there: given back to the card, or the refund would not be a full one
    private function restoreGiftCard(Basket $basket): void
    {
        if (!$this->basketCodeService->restore($basket)) {
            $this->logger->error('Order {number} refunded: the gift card {code} it was partly paid with no longer exists, {amount} not credited back', ['number' => $basket->getNumber(), 'code' => $basket->getDiscountCode(), 'amount' => $basket->getDiscountAmount()]);
        }
    }

    // A card bought by a refunded order is money the shop gave back already. Deactivated rather than emptied, so the balance still says what was left on it; the orders it already paid for stay as they are, their money having been spent before the refund
    private function deactivateGiftCards(Basket $basket): void
    {
        $cards = $this->giftCardService->findIssuedBy($basket);
        foreach ($cards as $card) {
            $card->setActive(false);
        }

        if ([] !== $cards) {
            $this->entityManager->flush();
        }
    }
}
