<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Enum;

// What a provider's notification says happened to the money, so BasketService knows whether to deliver an order or take it back without reading any provider's event names
enum PaymentEvent: string
{
    // The money arrived: the basket is delivered
    case Paid = 'paid';
    // Money was handed back, the amount being the total refunded so far as the provider counts it: in full the order is refunded, in part it stays paid and the shopkeeper is told
    case Refunded = 'refunded';
    // The bank sided with the cardholder and the money is gone for good: refunded whatever the amount, a lost dispute not being a gesture the shop chose to make
    case ChargebackLost = 'chargeback_lost';
}
