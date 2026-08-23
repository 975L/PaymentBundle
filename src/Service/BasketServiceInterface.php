<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

interface BasketServiceInterface
{
    /**
     * Adds $quantity of an item to the session's basket, creating the basket if there is none yet.
     * The request body carries the JSON payload {id, quantity, type}, "type" being the kind key of the
     * BasketItemProviderInterface owning the item. A negative quantity decrements, reaching 0 removes
     * the line; a digital item keeps its quantity of 1 whatever is asked.
     *
     * @return array{error: string}|array{basket: array<string, mixed>} the translated refusal from the
     *                                                                  provider's validateAddition(), or the updated basket
     *
     * @throws \Exception when no item matches the submitted id
     */
    public function addItem(Request $request): array;

    /**
     * Applies the one code the basket page asks for - a promotional code or a gift card, told apart by the service.
     *
     * @return array{basket?: array<string, mixed>, error?: string} the refused reason, translated, or the basket as it now stands
     */
    public function applyCode(Request $request): array;

    /**
     * Takes that code back off the basket.
     *
     * @return array{basket?: array<string, mixed>}
     */
    public function removeCode(): array;

    // Creates an empty basket and stores its id in the session.
    public function create(): Basket;

    // Builds the checkout form (delivery address, options) bound to $basket.
    public function createForm(string $name, Basket $basket): FormInterface;

    // Records the Payment row for the basket being validated.
    public function createPayment(?string $gatewayReference = null): void;

    /**
     * Opens a checkout session with the active payment provider for the current basket, one line per
     * basket entry plus one for the shipping when there is any.
     *
     * @return CheckoutSession the url to redirect the customer to, and what the provider calls that checkout by
     */
    public function createCheckout(?string $successUrl = null): CheckoutSession;

    /**
     * Deletes the whole basket and clears it from the session.
     *
     * @return array{total: int, quantity: int} always zeroed, for the front-end to reset its counters
     */
    public function delete(): array;

    /**
     * Removes one line from the basket, the request body carrying {id, type}.
     *
     * @return array{basket: array<string, mixed>}|array{} same shape as getJson()
     */
    public function deleteItem(Request $request): array;

    // Generates the token guarding the "basket paid" url against being guessed from an order number.
    public function generateSecurityToken(): string;

    // The session's current basket, or null when there is none.
    public function get(): ?Basket;

    /**
     * The current basket as the front-end consumes it.
     *
     * @return array{basket: array<string, mixed>}|array{} an empty array when there is no basket at all
     */
    public function getJson(): array;

    /**
     * Applies a payment the provider confirms to the basket it references, called from the webhook -
     * and delivers the basket it settles.
     *
     * A notification naming no known basket, or an amount other than the one the basket was numbered for, is
     * logged and dropped rather than raised: the provider would replay it for days over something it cannot fix.
     */
    public function applyNotification(PaymentNotification $notification): void;

    /**
     * Marks the basket's items of that kind as shipped and emails the customer their tracking number.
     *
     * @param string $number the basket's own order number
     * @param string $type   the item kind being shipped
     */
    public function itemsShipped(string $number, string $type): Basket;

    /**
     * Recomputes the basket's totals, VAT and shipping from its current lines.
     *
     * The lines themselves are read again off their providers first, as long as the basket has not been validated:
     * a basket sits for days, and it is charged at the price it is showing today, not at the one that stood when it
     * was filled. An order's lines are never touched again.
     */
    public function updateTotals(): void;

    /**
     * Validates the basket, assigns it an order number and a security token, and lets each item kind's
     * provider stash its own pre-payment data.
     *
     * @return string the absolute url to send the customer to - the provider's checkout page, or straight
     *                to the "paid" page when the total is zero and no payment is needed
     *
     * @throws BasketNotOrderableException when a provider says its own entries can no longer be ordered as the
     *                                     basket holds them - the basket is left untouched
     * @throws PaymentUnavailableException when the basket is to be charged and the active gateway holds no
     *                                     usable key - the basket is left untouched
     */
    public function validate(Request $request, bool $forSharing = false): string;

    /**
     * Opens the checkout of an order somebody else is settling, and hands back the page to send them to.
     *
     * @throws PaymentUnavailableException when the order is not waiting for a payment, or no gateway can take one
     */
    public function payShared(Basket $basket): string;

    /**
     * Writes the order a payment link stands for, and hands back the address to send whoever is going to settle it.
     *
     * The same frozen order payShared() charges, its single line typed by the shop instead of read from a
     * catalogue - a deposit, a repair, an invoice settled online. The e-mail is required: the confirmation is what
     * tells the customer their payment went through, and its blind copy is what tells the shop.
     *
     * @param string      $label       what the line is called, on the payer's page as at the provider
     * @param int         $amount      in the currency's smallest unit (cents), VAT included as every basket amount is
     * @param string      $email       whoever the link is sent to, the address the confirmation goes to
     * @param string|null $description shown under the label on the payer's page, when there is more to say
     *
     * @return string the absolute url of the payer's page, in its short form - a link sent by text message has
     *                160 characters to live in (see BasketController::shortPay())
     *
     * @throws \InvalidArgumentException   when the amount is not worth charging
     * @throws PaymentUnavailableException when the active gateway holds no usable key - nothing is written
     */
    public function createPaymentLink(string $label, int $amount, string $email, ?string $description = null): string;

    /**
     * Delivers a validated basket whose payment is settled - every provider's own post-payment effects, then the
     * confirmation email.
     *
     * Does nothing when the basket is not "validated", and nothing when it has something to pay and no payment
     * recorded as finished: the url the customer returns on is handed to them before they pay, and proves nothing.
     */
    public function paid(Basket $basket): void;

    /**
     * Confirms the customer's return to the site with the provider itself, then delivers the basket.
     *
     * The webhook confirms the same payment on its own, so this only shortens the wait for the customer standing
     * in front of the page; a provider that cannot be reached leaves the basket for the webhook to settle.
     */
    public function confirmReturn(Basket $basket, Request $request): void;
}
