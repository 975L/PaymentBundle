<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests;

use c975L\ConfigBundle\Test\CodeRulesTestCase;

// The audit's rules replayed on the bundle's own code (see ConfigBundle's CodeRulesTestCase)
class CodeRulesTest extends CodeRulesTestCase
{
    // Where the bundle's code lives
    protected function sourceDirectory(): string
    {
        return __DIR__ . '/../src';
    }

    // The namespace of that code
    protected function sourceNamespace(): string
    {
        return 'c975L\PaymentBundle';
    }

    // The legitimate exceptions, each with its reason
    protected function allowed(): array
    {
        return [
            self::BULK_QUERY => [
                'c975L\\PaymentBundle\\Controller\\BasketController::validate' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Controller\\BasketController::delete' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::claimPaid' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::releasePaid' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::markCodeClaimed' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::markCodeReleased' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::claimRefunded' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\BasketRepository::attachOrphansTo' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\DiscountRepository::claimUse' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\DiscountRepository::releaseUse' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\GiftCardRepository::claimAmount' => 'Visitor and order rows (baskets, discount uses, gift card balances), read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\GiftCardRepository::creditAmount' => 'A gift card balance, read live and never held in a cached fragment',
                'c975L\\PaymentBundle\\Repository\\InvoiceSequenceRepository::bump' => 'The invoice counter, an atomic claim read by nothing cached',
            ],
        ];
    }
}
