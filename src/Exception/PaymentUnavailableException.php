<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Exception;

// A basket that cannot be charged: no gateway named, or the one in use holding no key. The visitor gets a message and an untouched basket, where the provider's own exception used to reach them as a 500 (see PaymentAlertProvider, which says the same thing to the shopkeeper)
class PaymentUnavailableException extends \RuntimeException
{
}
