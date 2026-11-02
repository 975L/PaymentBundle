<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use c975L\ConfigBundle\Attribute\ActivityLogUnnamed;
use c975L\PaymentBundle\Entity\GiftCard;
use PHPUnit\Framework\TestCase;

class GiftCardTest extends TestCase
{
    // Without the attribute, the code a card is named by would stay in the activity log and its dumps, spendable by whoever reads them
    public function testTheCodeStaysOutOfTheActivityLog(): void
    {
        $this->assertCount(1, new \ReflectionClass(GiftCard::class)->getAttributes(ActivityLogUnnamed::class));
    }
}
