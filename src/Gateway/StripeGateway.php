<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Gateway;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Contract\VerifiableGatewayInterface;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;

// The one place of the bundle importing Stripe\*, which is what makes a second provider a matter of adding a class next to this one. Which pair of keys it uses is decided here and nowhere else: nothing outside reads a key to guess whether the site charges for real (see PaymentTestMode)
class StripeGateway implements ExpirableGatewayInterface, PaymentGatewayInterface, ReturnAwareGatewayInterface, VerifiableGatewayInterface
{
    public const string SLUG = 'stripe';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly PaymentTestModeInterface $testMode,
        private readonly StripeSessionReader $sessionReader,
    ) {
    }

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getSecret();
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $lineItems = [];
        foreach ($request->lines as $line) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $request->currency,
                    'product_data' => ['name' => $line['name']],
                    'unit_amount' => $line['amount'],
                ],
                'quantity' => $line['quantity'],
            ];
        }

        Stripe::setApiKey($this->getSecret());
        $checkoutSession = StripeSession::create([
            'line_items' => $lineItems,
            'mode' => 'payment',
            // Stripe swaps {CHECKOUT_SESSION_ID} for the real id when it sends the customer back, which is what readReturn() looks the payment up with - the url alone says nothing about whether it was paid
            'success_url' => $request->successUrl . (str_contains($request->successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->cancelUrl,
            'customer_email' => $request->email,
            'metadata' => $request->metadata,
        ]);

        return new CheckoutSession($checkoutSession->url, $checkoutSession->id);
    }

    // A Checkout Session stays payable at Stripe for 24 hours, and the customer editing their basket still has it open in a tab: expiring it is what stops them paying for a basket that no longer exists
    public function expireCheckout(string $reference): void
    {
        Stripe::setApiKey($this->getSecret());

        // Expiring is a call on the session itself, so it is fetched first - two round-trips on a path only an abandoned checkout reaches, against a customer able to pay for a basket that no longer exists
        StripeSession::retrieve($reference)->expire();
    }

    // Only "checkout.session.completed" says a basket is paid; every other event Stripe is configured to send is authentic and of no interest here, hence a null rather than a refusal
    public function readNotification(Request $request): ?PaymentNotification
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->headers->get('stripe-signature'),
                $this->getWebhookSecret(),
            );
        } catch (SignatureVerificationException | \UnexpectedValueException $e) {
            throw new InvalidNotificationException($e->getMessage(), 0, $e);
        }

        if ('checkout.session.completed' !== $event->type) {
            return null;
        }

        return $this->sessionReader->read($event->data->object);
    }

    // The customer coming back from Stripe, which is only ever a session id to look up: the session is re-read from Stripe itself, so a url typed by hand confirms nothing
    public function readReturn(Request $request, ?string $reference): ?PaymentNotification
    {
        // Stripe writes the session id into the url it sends the customer back on, and the reference kept on the payment names the same session: the second answers when the first has been dropped along the way
        $sessionId = $request->query->get('session_id');
        if (!is_string($sessionId) || '' === $sessionId) {
            $sessionId = $reference;
        }

        if (null === $sessionId || '' === $sessionId) {
            return null;
        }

        Stripe::setApiKey($this->getSecret());

        return $this->sessionReader->read(StripeSession::retrieve($sessionId));
    }

    // The test dashboard and the live one are two distinct spaces at Stripe, and a live payment id is not found in the test one
    public function getTransactionUrl(string $transactionId): ?string
    {
        return 'https://dashboard.stripe.com/' . ($this->testMode->isEnabled() ? 'test/' : '') . 'payments/' . $transactionId;
    }

    // Where Checkout hosts its payment page, the same host in test mode as live: the redirection leaving the basket form lands there, and the site's form-action has to name it
    public function getCheckoutDomains(): array
    {
        return ['checkout.stripe.com'];
    }

    // Lists a single Checkout Session, which tells a revoked or mistyped key from a well-formed one while asking for nothing the bundle does not already need: a restricted key scoped to Checkout alone answers this, where reading the account would be refused for lack of a permission no payment ever uses
    public function verifyCredentials(): ?string
    {
        if (!$this->isConfigured()) {
            return 'No secret key is set for the ' . ($this->testMode->isEnabled() ? 'test' : 'live') . ' mode';
        }

        try {
            Stripe::setApiKey($this->getSecret());
            StripeSession::all(['limit' => 1]);
        } catch (ApiErrorException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function getSecret(): string
    {
        return (string) $this->configService->get($this->testMode->isEnabled() ? 'stripe-secret-test' : 'stripe-secret');
    }

    private function getWebhookSecret(): string
    {
        return (string) $this->configService->get($this->testMode->isEnabled() ? 'stripe-webhook-secret-test' : 'stripe-webhook-secret');
    }
}
