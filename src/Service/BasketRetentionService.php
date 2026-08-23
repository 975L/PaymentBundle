<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

// How long a basket lives, in one place: the four durations below are what the law asks for rather than what a shop prefers, which is why they are constants of this bundle and not settings of each site. Deliberately not an interface, like BasketEmailFactory: there is no extension point to offer, a site free to keep its orders longer than ten years being a site that is no longer compliant
class BasketRetentionService
{
    // A basket nobody validated: no obligation holds it, only the visitor who may come back to it (see BasketRecoverySubscriber, whose recovery cookie lives exactly this long)
    public const int UNVALIDATED_DAYS = 14;

    // An order validated but never paid: not a sale, so nothing to keep - but the coordinates are filled in by then, which is precisely why it must not be kept
    public const int ABANDONED_DAYS = 30;

    // The legal warranty of conformity has run out: the order is still kept, but it has stopped being current business and leaves the back-office active list
    public const int ARCHIVE_YEARS = 2;

    // The accounting obligation of article L123-22 of the Code de commerce, counted from the close of the accounting year (see BasketRepository::findExpired)
    public const int RETENTION_YEARS = 10;

    private const int BATCH_SIZE = 20;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The whole nightly pass, in the order that leaves each step the least to do.
     *
     * @return array<string, int> what each step took away, keyed by step
     */
    public function run(): array
    {
        return [
            'unvalidated' => $this->deleteUnvalidated(),
            'abandoned' => $this->deleteAbandoned(),
            'archived' => $this->archiveDelivered(),
            'expired' => $this->deleteExpired(),
        ];
    }

    // Baskets nobody ever validated
    public function deleteUnvalidated(): int
    {
        return $this->delete($this->basketRepository->findUnvalidated(self::UNVALIDATED_DAYS));
    }

    /**
     * Orders validated but never paid, coordinates and all.
     *
     * Each one is written to the log before it goes, and this step alone: "taken and never confirmed" is not
     * told apart from "never paid" from here - a lost webhook, or a notification whose amount did not match,
     * leaves the very same row behind, and neither isFinished nor transactionId is filled in precisely in the
     * cases worth keeping. What is logged carries the order and its figures and no personal data at all, so
     * the purge stays the purge, and a customer who writes in a year later can still be answered.
     */
    public function deleteAbandoned(): int
    {
        $baskets = $this->basketRepository->findAbandoned(self::ABANDONED_DAYS);

        foreach ($baskets as $basket) {
            $payment = $basket->getPayment();
            $this->logger->warning('Abandoned order removed', [
                'number' => $basket->getNumber(),
                'total' => $basket->getTotal(),
                'payable' => $basket->getPayable(),
                'currency' => $basket->getCurrency(),
                'gateway' => $payment?->getGateway(),
                'gatewayReference' => $payment?->getGatewayReference(),
                'transactionId' => $payment?->getTransactionId(),
            ]);
        }

        return $this->delete($baskets);
    }

    // Orders whose ten years are up
    public function deleteExpired(): int
    {
        return $this->delete($this->basketRepository->findExpired(self::RETENTION_YEARS));
    }

    /**
     * Moves the orders that are no longer current business to the intermediate archive.
     *
     * A date and not a status: the checkout reads the status as a string in half a dozen places, and an
     * "archived" value there would have an order stop being recognised as the paid one it still is.
     */
    public function archiveDelivered(): int
    {
        $count = 0;
        $now = new \DateTime();

        foreach ($this->basketRepository->findToArchive(self::ARCHIVE_YEARS) as $basket) {
            $basket->setArchived($now);
            ++$count;

            if (0 === $count % self::BATCH_SIZE) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    /**
     * Removes those baskets and the payment attached to each.
     *
     * The relation is a OneToOne without cascade, so a payment whose basket goes without it is a row nothing
     * points to any more - and the personal data of an abandoned order is not what a lingering amount makes up for.
     *
     * @param Basket[] $baskets
     */
    private function delete(array $baskets): int
    {
        $count = 0;

        foreach ($baskets as $basket) {
            $payment = $basket->getPayment();
            $basket->setPayment(null);
            $this->entityManager->remove($basket);

            if (null !== $payment) {
                $this->entityManager->remove($payment);
            }

            ++$count;

            // Flushed by batches rather than one by one, but never cleared: the entities still to go were loaded before, and clearing the manager would detach them - which is what an ORM refuses to remove
            if (0 === $count % self::BATCH_SIZE) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return $count;
    }
}
