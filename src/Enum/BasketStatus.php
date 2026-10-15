<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Where a basket stands, from its first line to its parcel. Translatable so EasyAdmin offers the cases translated with no choice list to keep in step (same as SocialBundle's SocialPostStatus)
enum BasketStatus: string implements TranslatableInterface
{
    // Still being filled
    case New = 'new';
    // Address bound and total frozen, waiting for its payment
    case Validated = 'validated';
    case Paid = 'paid';
    case Shipped = 'shipped';
    // Money handed back in full by the provider, refund or lost chargeback alike: still an order on the books, but no longer one that hands anything over
    case Refunded = 'refunded';

    // Still in the customer's hands
    public const array OPEN = [self::New, self::Validated];

    // Paid for, whether or not it has left yet
    public const array SETTLED = [self::Paid, self::Shipped];

    // Every basket that became an order, refunded ones included: what the books, the archive and the integrity checks must keep seeing, while SETTLED says what still hands anything over
    public const array RECORDED = [self::Paid, self::Shipped, self::Refunded];

    // Whether the basket is an order, shipped or not
    public function isSettled(): bool
    {
        return \in_array($this, self::SETTLED, true);
    }

    // Whether the basket became an order at some point, refunded since or not - never a basket to fill again
    public function isRecorded(): bool
    {
        return \in_array($this, self::RECORDED, true);
    }

    // The label the back office and the customer area show for this status
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('label.basket_status_' . $this->value, [], 'payment', $locale);
    }
}
