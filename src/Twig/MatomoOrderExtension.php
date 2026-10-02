<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\VatCalculator;
use Twig\Attribute\AsTwigFunction;

// A paid order in the terms Matomo's e-commerce tracking reads it, amounts in units rather than cents - every bundle selling through the basket (shop, gallery prints, crowdfunding) is measured by this one call
class MatomoOrderExtension
{
    public function __construct(private readonly VatCalculator $vatCalculator)
    {
    }

    /**
     * @return array{id: ?string, grandTotal: float, subTotal: float, tax: float, shipping: float, discount: float, items: list<array{sku: string, name: string, category: string, price: float, quantity: int}>}
     */
    #[AsTwigFunction('payment_matomo_order')]
    public function order(Basket $basket): array
    {
        // One entry per line, named as the checkout names it, its kind as category so each selling bundle reads apart in the reports
        $items = [];
        foreach ($basket->getItems() as $type => $lines) {
            foreach ($lines as $id => $line) {
                $parentTitle = (string) ($line['parent']['title'] ?? '');
                $items[] = [
                    'sku' => $type . '-' . $id,
                    'name' => '' === $parentTitle ? (string) $line['item']['title'] : $parentTitle . ' (' . $line['item']['title'] . ')',
                    'category' => (string) $type,
                    'price' => self::units((int) $line['item']['price']),
                    'quantity' => (int) $line['quantity'],
                ];
            }
        }

        return [
            'id' => $basket->getNumber(),
            'grandTotal' => self::units($basket->getPayable()),
            'subTotal' => self::units((int) $basket->getTotal()),
            'tax' => self::units($this->vatCalculator->breakdown($basket)['amount']),
            'shipping' => self::units((int) $basket->getShipping()),
            'discount' => self::units($basket->getDiscountAmount()),
            'items' => $items,
        ];
    }

    // Always a float, a round amount divided by 100 otherwise coming back as an integer
    private static function units(int $cents): float
    {
        return $cents / 100;
    }
}
