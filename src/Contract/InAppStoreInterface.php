<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

// A mobile store selling inside an app - Apple's App Store, Google Play. Kept apart from PaymentGatewayInterface on purpose: the store is the merchant of record, so there is no checkout to open, no basket, no invoice and no VAT on this side, only a purchase to verify, deliver and take back on a refund. InAppStoreRegistry finds every implementation by itself
interface InAppStoreInterface
{
    // Stable identifier of the store ("apple", "google"), what the notification route carries and what a site records as the source of what it delivered
    public function getSlug(): string;

    // Whether the keys needed to verify a purchase are set - a store that cannot verify must say so rather than fail when the app calls
    public function isConfigured(): bool;

    // Asks the store itself about a purchase the app reports, never trusting what the phone says it bought
    /**
     * @param array<string, mixed> $proof what the app sends: "transactionId" for Apple, "productId" and "purchaseToken" for Google
     *
     * @return InAppTransaction|null null while the purchase is not completed (pending, cancelled)
     *
     * @throws \c975L\PaymentBundle\Exception\InvalidNotificationException when the store does not know the purchase or its signature does not check out
     * @throws \c975L\PaymentBundle\Exception\PaymentUnavailableException  when the store cannot be reached or refuses the keys
     */
    public function verifyPurchase(array $proof): ?InAppTransaction;

    // Tells the store the purchase was delivered - Google refunds one left unacknowledged for three days, Apple asks nothing; PaymentUnavailableException when the store cannot be reached
    public function acknowledge(InAppTransaction $transaction): void;

    // Reads a store notification, authenticity checked, and returns the refund it announces - or, paid, the refund it reverses
    /**
     * @return InAppTransaction|null null when the notification is authentic but announces nothing the site acts on
     *
     * @throws \c975L\PaymentBundle\Exception\InvalidNotificationException when the payload or its signature does not check out
     */
    public function readNotification(Request $request): ?InAppTransaction;

    // Whether the keys needed to write the catalogue are set, which are not the ones verifying a purchase at Apple
    public function canPublish(): bool;

    // Creates the product at the store or brings it up to date - names, descriptions, price, and for Apple the review screenshot - so nothing is ever typed in a store console by hand
    public function publishProduct(InAppProduct $product): void;
}
