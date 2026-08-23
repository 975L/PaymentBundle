<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Provider;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\PaymentLinkItem;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\VatCalculator;

// The only item provider this bundle ships for itself: a line whose label and price are typed by the shopkeeper instead of read from a catalogue (see BasketService::createPaymentLink()). Registered like any other, because BasketService::paid() looks its kind up in the registry - a basket holding a kind nobody answers for cannot be delivered
class PaymentLinkItemProvider implements BasketItemProviderInterface
{
    public const string KIND = 'payment_link';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // Nothing to resolve: a payment link is minted in the back-office and never added from a page, which is exactly what keeps a visitor from posting themselves a line worth what they choose
    public function findItem(int | string $id): ?object
    {
        return null;
    }

    // The refusal findItem() already implies, said out loud: the front's "add to basket" must never accept this kind, whatever it is handed
    public function validateAddition(object $item, int $quantity): ?string
    {
        return 'error.payment_link_not_addable';
    }

    // A typed amount runs out of no stock and expires on no date - the line is worth today what it was worth when it was written
    public function validateCheckout(Basket $basket, array $itemsOfThisKind): ?string
    {
        return null;
    }

    /**
     * Builds the line the payer's page, the checkout and the confirmation email all read.
     *
     * The one place that shape is written, the creation service calling this rather than hand-building an array
     * the templates would then have to be told about twice. "parent" is left empty on purpose: a payment link
     * hangs under no catalogue entry, and the components draw the line without a link and without a picture
     * when it names none.
     */
    public function toBasketData(object $item, int $quantity): array
    {
        if (!$item instanceof PaymentLinkItem) {
            throw new \InvalidArgumentException(sprintf('A payment link line is built from a %s, not from a %s', PaymentLinkItem::class, $item::class));
        }

        $rate = $this->vatRate();
        $total = $item->amount * $quantity;

        return [
            'item' => [
                'id' => PaymentLinkItem::ID,
                'title' => $item->label,
                'description' => $item->description ?? '',
                'price' => $item->amount,
                'currency' => (string) $this->configService->get('shop-currency'),
                'vat' => $rate,
                // Read by the components as guaranteed keys rather than as optional ones, so they are written even when they hold nothing
                'media' => null,
                'slug' => null,
                'limitedQuantity' => 0,
                // What Item:Type prints: paying for a link settles a service, and there is nothing to ship afterwards
                'service' => true,
            ],
            'parent' => [
                'title' => '',
                'slug' => null,
                'image' => false,
            ],
            'type' => self::KIND,
            'quantity' => $quantity,
            'totalVat' => VatCalculator::included($total, $rate),
            'total' => $total,
        ];
    }

    // A service and nothing else: PaymentStatusProvider counts the physical baskets left to ship, and a link settled would otherwise sit in that list for good
    public function getContentFlags(array $itemData): int
    {
        return Basket::CONTENT_FLAG_SERVICE;
    }

    // Nothing to carry over to the delivery: the line is entirely held by the basket it was written on
    public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array
    {
        return [];
    }

    // Nothing to deliver: being paid is what a payment link is for, and the confirmation email says so on its own
    public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void
    {
    }

    // The rate the shop takes out of what it typed, prices being held VAT included everywhere here. Zero for a shop that charges none, which is what the setting holds until it is filled in
    private function vatRate(): float
    {
        return max(0.0, (float) $this->configService->get('payment-link-vat-rate'));
    }
}
