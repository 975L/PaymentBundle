<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\StatusProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Doctrine\ORM\QueryBuilder;

// The numbers a maintainer acts on the morning they read them, read across every site at once: the orders waiting to be shipped, the payments that started and never came back, and whether the site is still charging with test keys
// These live here and not in ShopBundle, which used to report them: all three are read off Basket, which belongs to this bundle - and a site running Payment without Shop (CrowdfundingBundle does) reported nothing at all
// A plain count of baskets or of payments stays out: it is looked at once, decides nothing, and drowns what matters
class PaymentStatusProvider implements StatusProviderInterface
{
    // Past this, a basket still "validated" is a payment that was started and never confirmed - the provider took the customer away and nothing came back
    private const int STALLED_PAYMENT_HOURS = 24;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly PaymentTestModeInterface $paymentTestMode,
    ) {
    }

    public function getStatusKey(): string
    {
        return 'payment';
    }

    public function getStatusData(): array
    {
        return [
            'testMode' => $this->paymentTestMode->isEnabled(),
            'gateway' => (string) $this->configService->get('payment-gateway'),
            'ordersToShip' => $this->ordersToShip(),
            'oldestOrderToShip' => $this->oldestOrderToShip(),
            'stalledPayments' => $this->stalledPayments(),
        ];
    }

    // Paid, holding something physical, and not shipped yet - a digital item is delivered by its message handler and needs nobody
    private function toShipQuery(): QueryBuilder
    {
        return $this->basketRepository->createQueryBuilder('b')
            ->where('b.status = :paid')
            ->andWhere('b.number IS NOT NULL')
            ->andWhere('BIT_AND(b.contentflags, :physical) > 0')
            ->andWhere('b.itemsShipped IS NULL')
            ->setParameter('paid', 'paid')
            ->setParameter('physical', Basket::CONTENT_FLAG_PHYSICAL);
    }

    private function ordersToShip(): int
    {
        return (int) $this->toShipQuery()
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // The date of the oldest one, which is what says whether a backlog is a busy morning or a forgotten week
    private function oldestOrderToShip(): ?string
    {
        $oldest = $this->toShipQuery()
            ->select('MIN(b.modification)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $oldest ? null : new \DateTime((string) $oldest)->format('Y-m-d');
    }

    private function stalledPayments(): int
    {
        return (int) $this->basketRepository->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :validated')
            ->andWhere('b.modification < :before')
            ->setParameter('validated', 'validated')
            ->setParameter('before', new \DateTime('-' . self::STALLED_PAYMENT_HOURS . ' hours'))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
