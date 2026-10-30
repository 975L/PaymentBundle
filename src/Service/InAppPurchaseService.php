<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Entity\StorePurchase;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\InAppProductProviderRegistry;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Repository\StorePurchaseRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The one path a store purchase takes: verified with the store, matched to its holder, recorded, delivered by the site's provider, acknowledged - and taken back the same way on a refund
class InAppPurchaseService implements InAppPurchaseServiceInterface
{
    public function __construct(
        private readonly InAppStoreRegistry $storeRegistry,
        private readonly InAppProductProviderRegistry $providerRegistry,
        private readonly StorePurchaseRepository $purchaseRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    // Derived from the holder and the site's secret rather than stored: the same holder always buys under the same token, and nobody can work out another's
    public function accountToken(string $holder): string
    {
        $hex = substr(hash_hmac('sha256', $holder, $this->secret), 0, 32);
        $hex[12] = '8';
        $hex[16] = dechex(8 | (hexdec($hex[16]) & 3));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)]);
    }

    public function verify(string $store, string $holder, array $proof): bool
    {
        if (!$this->storeRegistry->has($store) || !$this->storeRegistry->get($store)->isConfigured()) {
            throw new PaymentUnavailableException(sprintf('The store "%s" cannot verify purchases', $store));
        }

        $transaction = $this->storeRegistry->get($store)->verifyPurchase($proof);
        if (null === $transaction || PaymentEvent::Paid !== $transaction->event) {
            return false;
        }
        if ($transaction->accountToken !== $this->accountToken($holder)) {
            throw new InvalidNotificationException('The purchase was made under another account');
        }
        $provider = $this->providerRegistry->forProduct($transaction->productId) ?? throw new InvalidNotificationException(sprintf('Nothing here sells "%s"', $transaction->productId));

        $purchase = $this->purchaseRepository->findOneByTransaction($store, $transaction->transactionId);
        if (null !== $purchase && $purchase->getHolder() !== $holder) {
            return false;
        }

        // Recorded before delivering, so a delivery or an acknowledgement failing leaves a row that a retry or a refund finds back; a refunded row the store calls paid again is a refund it reversed
        if (null === $purchase) {
            try {
                $this->entityManager->persist(new StorePurchase($store, $transaction->transactionId, $transaction->productId, $holder, $transaction->environment));
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // The same purchase recorded meanwhile by a concurrent request, which delivers it
                return true;
            }
        } elseif ($purchase->isRefunded()) {
            $purchase->markRestored();
            $this->entityManager->flush();
        }

        $provider->deliver($holder, $transaction->productId, $store);
        $this->storeRegistry->get($store)->acknowledge($transaction);

        return true;
    }

    // A refund names the transaction only: the holder and the product are the ones recorded when it was delivered. A paid one is a refund the store reversed, giving back what it took
    public function applyNotification(InAppTransaction $transaction): void
    {
        $purchase = $this->purchaseRepository->findOneByTransaction($transaction->store, $transaction->transactionId);
        $isReversal = PaymentEvent::Paid === $transaction->event;
        if (null === $purchase || $purchase->isRefunded() !== $isReversal) {
            return;
        }

        $provider = $this->providerRegistry->forProduct($purchase->getProductId());
        if ($isReversal) {
            $purchase->markRestored();
            $this->entityManager->flush();
            $provider?->deliver($purchase->getHolder(), $purchase->getProductId(), $purchase->getStore());

            return;
        }

        $purchase->markRefunded();
        $provider?->revoke($purchase->getHolder(), $purchase->getProductId(), $purchase->getStore());
        $this->entityManager->flush();
    }
}
