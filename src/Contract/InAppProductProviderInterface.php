<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Plugs what a site sells in its app into the stores, as BasketItemProviderInterface does for the basket: the bundle verifies, records and takes back, the site says what it sells and what a purchase gives. $holder is whatever the site calls the one who owns what is bought - a user id, a device's anonymous code, 191 characters at most - the bundle only ever compares and stores it
interface InAppProductProviderInterface
{
    // Everything for sale in the app, as the stores are to be told
    /** @return iterable<InAppProduct> */
    public function getProducts(): iterable;

    // Whether this provider sells the product, so the registry knows whom a purchase goes to
    public function supports(string $productId): bool;

    // Gives what was bought; called again on a restore, a retry or a refund the store reversed, so giving what is already held must be harmless
    public function deliver(string $holder, string $productId, string $store): void;

    // Takes back what a refund cancelled
    public function revoke(string $holder, string $productId, string $store): void;
}
