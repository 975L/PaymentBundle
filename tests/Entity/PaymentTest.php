<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use c975L\PaymentBundle\Entity\Payment;
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    // A payment without currency yet reads none, one with it reads it uppercase
    public function testCurrency(): void
    {
        $payment = new Payment();

        $this->assertNull($payment->getCurrency());

        $payment->setCurrency('eur');

        $this->assertSame('EUR', $payment->getCurrency());
    }
}
