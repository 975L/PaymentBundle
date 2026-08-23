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

// A Twig function rather than a column carried across the checkout: the rate a line was added with is already frozen in the basket, so the tax is read back from it whenever a page or an email states it (see VatCalculator)
class VatExtension
{
    public function __construct(private readonly VatCalculator $vatCalculator)
    {
    }

    /**
     * @return array{rates: list<array{rate: float, total: int, base: int, amount: int}>, amount: int}
     */
    #[AsTwigFunction('payment_vat')]
    public function vat(Basket $basket): array
    {
        return $this->vatCalculator->breakdown($basket);
    }

    /**
     * The rate one line is taxed at, or null when it is taxed at none - what a page, an email and an invoice state beside the article.
     *
     * @param array<string, mixed> $itemData
     */
    #[AsTwigFunction('payment_vat_rate')]
    public function lineRate(array $itemData, string $type): ?float
    {
        return $this->vatCalculator->lineRate($type, $itemData);
    }
}
