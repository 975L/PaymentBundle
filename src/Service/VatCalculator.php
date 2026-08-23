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
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;

// The VAT a basket carries, computed on read and never stored: a line holds the rate the item was added with and the shipping and the discount are the basket's own columns, so an order answers the same amounts the day an item's rate is changed in the back-office
class VatCalculator
{
    public function __construct(
        private readonly BasketItemProviderRegistry $itemProviderRegistry,
    ) {
    }

    // The VAT held in an amount that already includes it, which is the one formula the whole ecosystem takes it out with - a line of a basket, a bucket of this breakdown
    public static function included(int $total, float $rate): int
    {
        return $rate <= 0 ? 0 : (int) round($total * $rate / (100 + $rate));
    }

    /**
     * The basket's VAT, one entry per rate, all amounts in cents.
     *
     * Prices are held VAT included, which is what the customer is charged, so the tax is taken out of the total rather than added to it.
     *
     * @return array{rates: list<array{rate: float, total: int, base: int, amount: int}>, amount: int}
     */
    public function breakdown(Basket $basket): array
    {
        $totals = $this->totalsByRate($basket);

        if ([] === $totals) {
            return ['rates' => [], 'amount' => 0];
        }

        $rates = [];
        $amount = 0;
        foreach ($this->spread($totals, $this->adjustment($basket)) as $rate => $total) {
            $vat = self::included($total, (float) $rate);
            $rates[] = ['rate' => (float) $rate, 'total' => $total, 'base' => $total - $vat, 'amount' => $vat];
            $amount += $vat;
        }

        return ['rates' => $rates, 'amount' => $amount];
    }

    /**
     * The rate one line of the basket is taxed at, or null when it is taxed at none.
     *
     * The one place that answers it, so a page, an email and an invoice state the same rate as the breakdown they
     * sit beside - the rate is never read straight off the line anywhere else. Null and not zero: an order placed
     * before the rate was kept carries a zero it was never charged at, and a shop charging no VAT states why in its
     * own mentions rather than printing "0 %" on every line.
     *
     * @param array<string, mixed> $itemData
     */
    public function lineRate(string $type, array $itemData): ?float
    {
        // Money bought in advance is taxed the day it is spent and not the day it is sold, so a gift card is taxed at no rate at all (see Basket::CONTENT_FLAG_GIFT_CARD)
        if (($this->itemProviderRegistry->get($type)->getContentFlags($itemData) & Basket::CONTENT_FLAG_GIFT_CARD) > 0) {
            return null;
        }

        $rate = (float) ($itemData['item']['vat'] ?? 0);

        return $rate > 0 ? $rate : null;
    }

    // What the basket holds, VAT included, grouped by the rate each line was added with - a line taxed at no rate weighs nothing here and is left out
    /**
     * @return array<numeric-string, int>
     */
    private function totalsByRate(Basket $basket): array
    {
        $totals = [];

        foreach ($basket->getItems() as $type => $items) {
            foreach ($items as $itemData) {
                $rate = $this->lineRate((string) $type, $itemData);
                if (null === $rate) {
                    continue;
                }

                $key = (string) $rate;
                $totals[$key] = ($totals[$key] ?? 0) + (int) $itemData['total'];
            }
        }

        ksort($totals, \SORT_NUMERIC);

        return $totals;
    }

    // The shipping is taxed at the rate of the goods it carries, and a promotional code lowers what they are sold for. A gift card does neither: it pays the order, it does not discount it
    private function adjustment(Basket $basket): int
    {
        $adjustment = (int) $basket->getShipping();

        if ('gift_card' !== $basket->getDiscountKind()) {
            $adjustment -= $basket->getDiscountAmount();
        }

        return $adjustment;
    }

    // Shares the shipping and the discount between the rates, in proportion of what each one weighs - the last one takes what the roundings left, so the shares always add up to the amount they came from
    /**
     * @param array<numeric-string, int> $totals
     *
     * @return array<numeric-string, int>
     */
    private function spread(array $totals, int $adjustment): array
    {
        $taxable = array_sum($totals);

        // Nothing to share, or nothing to share it over: lines taxed at a rate but sold for nothing - samples, counterparts given away - add up to a base of zero, and there is no proportion to take of it. The shipping is then left untaxed rather than landing on a rate picked at random
        if (0 === $adjustment || $taxable <= 0) {
            return $totals;
        }

        $keys = array_keys($totals);
        $last = array_key_last($keys);
        $distributed = 0;

        foreach ($keys as $index => $key) {
            $share = $index === $last ? $adjustment - $distributed : (int) round($adjustment * $totals[$key] / $taxable);
            $distributed += $share;
            // A code taking more off than the line is worth leaves nothing to tax rather than a negative base
            $totals[$key] = max(0, $totals[$key] + $share);
        }

        return $totals;
    }
}
