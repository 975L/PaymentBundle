<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Exception;

// A basket that can no longer be ordered as it stands: what it holds ran out, was withdrawn or was taken offline between the moment it was filled and the moment it is checked out.
// Its message is the provider's own, already translated, because only the bundle owning the item can say what is wrong with it. Thrown before anything is written, so the basket the visitor comes back to is the one they left.
class BasketNotOrderableException extends \RuntimeException
{
}
