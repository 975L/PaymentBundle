<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Exception;

// A payload that does not come from the provider it claims to, or that cannot be read at all - the webhook answers 400 on it, which is what every provider retries on
class InvalidNotificationException extends \RuntimeException
{
}
