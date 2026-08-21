<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use c975L\PaymentBundle\Entity\Basket;

// Plugs a bundle's own sellable items into the checkout engine, which only ever knows their "kind".
interface BasketItemProviderInterface
{
    // The Basket::$items key this provider is responsible for (e.g. "product", "crowdfunding").
    public function getKind(): string;

    // Resolves a sellable item by id, or null if not found/no longer available.
    public function findItem(int | string $id): ?object;

    /**
     * @return string|null a translated error message if $quantity of $item cannot be added (stock/dates/...), null if OK
     */
    public function validateAddition(object $item, int $quantity): ?string;

    /**
     * Called at the very top of the checkout, before anything is numbered, charged or written, to say whether this
     * provider's entries can still be ordered as the basket holds them.
     *
     * This is not validateAddition() again: that one is asked one click at a time and knows nothing of what the basket
     * already holds, where this one receives the whole quantity to compare against what is left. It is also the only
     * check standing between filling a basket and paying for it - a basket sits for days, and what it holds can run
     * out, be withdrawn or be taken offline in between.
     *
     * @param array<int|string, array<string, mixed>> $itemsOfThisKind this provider's own basket entries, keyed by item id
     *
     * @return string|null a translated error message if the basket can no longer be ordered, null if OK
     */
    public function validateCheckout(Basket $basket, array $itemsOfThisKind): ?string;

    /**
     * Builds the array stored in Basket::$items[kind][itemId] - same shape for every kind.
     *
     * @return array{item: array<string, mixed>, parent: array<string, mixed>, type: string, quantity: int, totalVat: int, total: int}
     */
    public function toBasketData(object $item, int $quantity): array;

    /**
     * Basket::CONTENT_FLAG_* bitmask contributed by one basket item entry of this kind (digital/physical/service/shipping...).
     *
     * @param array<string, mixed> $itemData one entry as toBasketData() built it
     */
    public function getContentFlags(array $itemData): int;

    /**
     * Called once the basket is validated, before the customer is sent to the provider, to hand over whatever this
     * provider will need back when the basket is delivered.
     *
     * Hand it over rather than stash it: what is returned here is persisted on the basket and given back verbatim
     * to onBasketPaid(). Keeping it in the visitor's session does not work - the payment provider confirms the
     * payment on a request of its own, which carries no session of that customer - and keeping it in the provider's
     * own tables means writing rows for a basket that may never be paid for.
     *
     * @param array<int|string, array<string, mixed>> $itemsOfThisKind this provider's own basket entries, keyed by item id
     * @param array<string, mixed>                    $requestData     the checkout form's raw POST data
     *
     * @return array<string, mixed> handed back to onBasketPaid(); an empty array when there is nothing to carry over
     */
    public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array;

    /**
     * Called once the basket is paid, and once only, to apply the provider's own domain effects.
     *
     * Reached from the payment provider's webhook as well as from the customer's return to the site, so it must
     * read nothing off the current request - $checkoutData is what the site kept for it instead.
     *
     * @param array<int|string, array<string, mixed>> $itemsOfThisKind this provider's own basket entries, keyed by item id
     * @param array<string, mixed>                    $checkoutData    what onBasketValidated() handed over, empty if nothing
     */
    public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void;
}
