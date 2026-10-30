<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Contract\InAppTransaction;

// What a site calls to sell in its app: the token the app buys under, the purchase it reports, the refund a store announces
interface InAppPurchaseServiceInterface
{
    // The UUID the app sets when buying (appAccountToken at Apple, obfuscatedAccountId at Google), so a purchase is tied to its holder and a proof replayed by someone else unlocks nothing
    public function accountToken(string $holder): string;

    // Verifies a purchase with its store, then delivers it to the holder - again on a restore, harmlessly
    /**
     * @param array<string, mixed> $proof what the app sends (see InAppStoreInterface::verifyPurchase())
     *
     * @return bool false while the purchase is not completed, or once refunded
     *
     * @throws \c975L\PaymentBundle\Exception\PaymentUnavailableException  when the store is unknown, holds no key or cannot be reached
     * @throws \c975L\PaymentBundle\Exception\InvalidNotificationException when the proof does not check out, belongs to another holder or sells nothing here
     */
    public function verify(string $store, string $holder, array $proof): bool;

    // Takes back what a refund announced by a store cancelled, and gives it back when the store reverses that refund
    public function applyNotification(InAppTransaction $transaction): void;
}
