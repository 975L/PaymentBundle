<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// What a provider answers when a checkout is opened: where to send the customer, and what to call that checkout by afterwards
final readonly class CheckoutSession
{
    /**
     * @param string      $url       the absolute url to send the customer to
     * @param string|null $reference what the provider calls this checkout, kept on the payment so the site can
     *                               expire it if the basket is edited before it is paid; null from a provider
     *                               that names its checkouts nothing
     */
    public function __construct(
        public string $url,
        public ?string $reference = null,
    ) {
    }
}
