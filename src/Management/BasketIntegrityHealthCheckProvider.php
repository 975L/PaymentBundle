<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckErrorRow;
use c975L\ConfigBundle\Management\HealthCheckSiteWideInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\PaymentRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// The failures a shop never sees: they leave no error page, no log line and no red screen anywhere - the customer is charged and nothing follows, an order is delivered and no payment answers for it, a basket holds an article that no longer exists. Each one is read off two rows that are only ever looked at apart, which is exactly what nobody does by hand
//
// One row per check rather than one per offending order (see IntrusionHealthCheckProvider, same shape): a shop with fifty stalled orders would otherwise push every other check off the dashboard, and the orders themselves are listed under their row by BasketIntegrityHealthCheckAdviceProvider
//
// Orders placed in test mode are out of all six, the queries themselves leaving them behind: a shop trying its checkout out writes orders nobody settles, delivers or invoices, and every one of them would read here as a defect
class BasketIntegrityHealthCheckProvider implements HealthCheckSiteWideInterface
{
    public const string KIND = 'basket-integrity';

    // Suffixes the rows are keyed by, appended to the site root so each check keeps a history of its own (results are stored per url and kind)
    public const string ROW_CHARGED_NOT_DELIVERED = '#charged-not-delivered';
    public const string ROW_DELIVERED_UNPAID = '#delivered-unpaid';
    public const string ROW_AMOUNT_MISMATCH = '#amount-mismatch';
    public const string ROW_MISSING_NUMBER = '#missing-number';
    public const string ROW_TOTAL_MISMATCH = '#total-mismatch';
    public const string ROW_UNRESOLVABLE_ITEMS = '#unresolvable-items';

    // How far back the orders are read. A database carries orders written by older versions of this engine, imported from elsewhere or migrated (see BasketLine::VERSION): reporting a three-year-old order for a shape that changed since is noise, and noise is what makes a dashboard stop being read
    private const int MONTHS = 12;

    // The webhook and the customer's return settle an order within seconds of the charge - a payment confirmed while the run was under way is on its way to being delivered, not stuck
    private const int GRACE_MINUTES = 60;

    // Enough to see there is a problem and which orders it started with; the count says how big it is
    private const int MAX_OFFENDERS = 50;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly BasketItemProviderRegistry $itemProviderRegistry,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    public function runChecks(): array
    {
        // Same guard as every site-wide check: without a site url there is nothing to key a row on
        $siteRoot = $this->siteUrlResolver->siteRoot();
        if (null === $siteRoot) {
            return [];
        }

        $since = new \DateTime('-' . self::MONTHS . ' months');

        return [
            $this->guard($siteRoot . self::ROW_CHARGED_NOT_DELIVERED, 'label.health_check_basket_charged_not_delivered', fn () => $this->chargedNotDelivered($since)),
            $this->guard($siteRoot . self::ROW_DELIVERED_UNPAID, 'label.health_check_basket_delivered_unpaid', fn () => $this->deliveredUnpaid($since)),
            $this->guard($siteRoot . self::ROW_AMOUNT_MISMATCH, 'label.health_check_basket_amount_mismatch', fn () => $this->amountMismatch($since)),
            $this->guard($siteRoot . self::ROW_MISSING_NUMBER, 'label.health_check_basket_missing_number', fn () => $this->missingNumber($since)),
            $this->guard($siteRoot . self::ROW_TOTAL_MISMATCH, 'label.health_check_basket_total_mismatch', fn () => $this->totalMismatch($since)),
            $this->guard($siteRoot . self::ROW_UNRESOLVABLE_ITEMS, 'label.health_check_basket_unresolvable_items', fn () => $this->unresolvableItems()),
        ];
    }

    // The customer was charged and the order was never delivered: no stock decremented, no file minted, no confirmation sent, and the back-office still lists it as awaiting payment
    private function chargedNotDelivered(\DateTimeInterface $since): array
    {
        $offenders = [];

        foreach ($this->paymentRepository->findFinishedWithoutDeliveredBasket($since, new \DateTime('-' . self::GRACE_MINUTES . ' minutes'), self::MAX_OFFENDERS) as $payment) {
            $basket = $payment->getBasket();

            $offenders[] = [
                'basketId' => $basket?->getId(),
                'paymentId' => $payment->getId(),
                'number' => $basket?->getNumber(),
                'info' => $this->amount($payment->getAmount(), $payment->getCurrency()) . ' - ' . ($basket?->getStatus() ?? 'no basket'),
            ];
        }

        return $offenders;
    }

    // The other way round: the order went out and no payment ever answered for it. An order with nothing to pay carries no payment row at all and is left out by the query itself
    private function deliveredUnpaid(\DateTimeInterface $since): array
    {
        return array_map(
            fn (Basket $basket) => $this->offender($basket, null === $basket->getPayment() ? 'no payment' : 'payment not finished'),
            $this->basketRepository->findDeliveredWithoutFinishedPayment($since, self::MAX_OFFENDERS),
        );
    }

    // What the provider was asked for against what the order adds up to - a gift card, a promotional code or a delivery charge applied on one side and not the other reconciles to the cent everywhere except here
    private function amountMismatch(\DateTimeInterface $since): array
    {
        $offenders = [];

        foreach ($this->basketRepository->findWithPaymentAmountMismatch($since, self::MAX_OFFENDERS) as $basket) {
            $payment = $basket->getPayment();

            // Settled on the very method the checkout charges with, the query having only narrowed the candidates down
            if (null === $payment || ($payment->getAmount() === $basket->getPayable() && $this->sameCurrency($basket, $payment))) {
                continue;
            }

            $offenders[] = $this->offender($basket, $this->amount($payment->getAmount(), $payment->getCurrency()) . ' != ' . $this->amount($basket->getPayable(), $basket->getCurrency()));
        }

        return $offenders;
    }

    // A delivered order carrying no invoice number is one no invoice can ever be drawn for
    private function missingNumber(\DateTimeInterface $since): array
    {
        return array_map(
            fn (Basket $basket) => $this->offender($basket, 'order of ' . $basket->getCreation()?->format('Y-m-d')),
            $this->basketRepository->findDeliveredWithoutNumber($since, self::MAX_OFFENDERS),
        );
    }

    // An order's own lines against the totals stored beside them - what the invoice, the emails and the accounting all read years later, each from its own side
    private function totalMismatch(\DateTimeInterface $since): array
    {
        $offenders = [];

        foreach ($this->basketRepository->findOrdersSince($since) as $basket) {
            $summed = $this->sumLines($basket);

            // Nothing is said about an order whose lines cannot be added up: a shape older than the keys read here is not a defect, it is an order written before them
            if (null === $summed) {
                continue;
            }

            if ($summed['total'] !== (int) $basket->getTotal()) {
                $offenders[] = $this->offender($basket, 'lines ' . $summed['total'] . ' != total ' . (int) $basket->getTotal());
            } elseif ($summed['quantity'] !== (int) $basket->getQuantity()) {
                $offenders[] = $this->offender($basket, 'lines ' . $summed['quantity'] . ' != quantity ' . (int) $basket->getQuantity());
            }

            if (\count($offenders) >= self::MAX_OFFENDERS) {
                break;
            }
        }

        return $offenders;
    }

    // A basket holding what the catalogue no longer has: the customer fills it, comes back, and the checkout refuses it without anybody being told - the withdrawn article is only named by an id in a json column
    private function unresolvableItems(): array
    {
        $offenders = [];

        foreach ($this->basketRepository->findPayable() as $basket) {
            $missing = $this->missingItemsOf($basket);

            if ([] !== $missing) {
                $offenders[] = $this->offender($basket, implode(', ', $missing));
            }

            if (\count($offenders) >= self::MAX_OFFENDERS) {
                break;
            }
        }

        return $offenders;
    }

    // The lines of one basket whose article cannot be resolved any more, named as "kind #id". A kind no bundle registers is one of them: the bundle selling it was removed while baskets still held it
    private function missingItemsOf(Basket $basket): array
    {
        $missing = [];

        foreach ($basket->getItems() as $kind => $itemsOfThisKind) {
            if (!$this->itemProviderRegistry->has($kind)) {
                $missing[] = $kind . ' (unknown kind)';
                continue;
            }

            $provider = $this->itemProviderRegistry->get($kind);

            foreach (array_keys($itemsOfThisKind) as $id) {
                if (null === $provider->findItem($id)) {
                    $missing[] = $kind . ' #' . $id;
                }
            }
        }

        return $missing;
    }

    // What the lines of an order add up to, or null when they cannot be added up - a line written before this bundle stored a total per line says nothing about the order being wrong
    private function sumLines(Basket $basket): ?array
    {
        $total = 0;
        $quantity = 0;

        foreach ($basket->getItems() as $itemsOfThisKind) {
            foreach ($itemsOfThisKind as $line) {
                if (!isset($line['total'], $line['quantity'])) {
                    return null;
                }

                $total += (int) $line['total'];
                $quantity += (int) $line['quantity'];
            }
        }

        return ['total' => $total, 'quantity' => $quantity];
    }

    private function sameCurrency(Basket $basket, Payment $payment): bool
    {
        return strtolower((string) $payment->getCurrency()) === strtolower((string) $basket->getCurrency());
    }

    // The order as the dashboard names it, its id carried alongside so the advice can link straight to it
    private function offender(Basket $basket, string $info): array
    {
        return [
            'basketId' => $basket->getId(),
            'paymentId' => $basket->getPayment()?->getId(),
            'number' => $basket->getNumber(),
            'info' => $info,
        ];
    }

    // Cents as they are stored, spelled with their currency: these strings are read by whoever compares them to the provider's own dashboard
    private function amount(?int $amount, ?string $currency): string
    {
        return number_format((int) $amount / 100, 2, '.', '') . ' ' . strtoupper((string) $currency);
    }

    /**
     * Runs one check and turns what it found into its row, a check that blows up saying so rather than taking the five others with it.
     *
     * HealthCheckRunner drops every row of a provider that throws (see its own catch), so an unresolvable article
     * in one basket would otherwise leave the dashboard with nothing at all - and nothing at all reads as "no problem".
     */
    private function guard(string $url, string $labelId, callable $check): array
    {
        $label = $this->translator->trans($labelId, [], 'payment');

        try {
            $offenders = $check();
        } catch (\Throwable $e) {
            return HealthCheckErrorRow::build($this->translator, 'payment', $url, $label, 'label.health_check_basket_check_failed', $e->getMessage());
        }

        return [
            'url' => $url,
            'label' => $label,
            'status' => [] === $offenders ? HealthCheckResult::STATUS_OK : HealthCheckResult::STATUS_ERROR,
            'summary' => $this->translator->trans($labelId . ([] === $offenders ? '_ok' : '_ko'), ['%count%' => \count($offenders)], 'payment'),
            'details' => ['offenders' => $offenders],
        ];
    }
}
