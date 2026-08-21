---
name: c975l-payment-gateway
description: "Use this skill when adding, configuring or debugging a payment provider in a c975L site — the gateway contract, the Stripe implementation, webhook signatures, the three optional interfaces, the test keys and the gateway health check. Covers what belongs behind the contract and what must never leak out of it. Triggers on: PaymentGatewayInterface, PaymentGatewayRegistry, StripeGateway, StripeSessionReader, CheckoutRequest, CheckoutSession, PaymentNotification, ReturnAwareGatewayInterface, ExpirableGatewayInterface, VerifiableGatewayInterface, GatewayHealthCheckProvider, PaymentAlertProvider, payment-gateway, stripe-secret, stripe-webhook-secret, checkout.session.completed, payment_status."
---

# c975L PaymentBundle — payment providers

> Everything a payment provider knows — keys, signatures, event shapes, API calls — stays behind one interface. The rest of the bundle only ever sees a url to redirect to and a `PaymentNotification`.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Contract/PaymentGatewayInterface.php`, `src/Contract/CheckoutRequest.php`, `src/Contract/CheckoutSession.php`, `src/Contract/PaymentNotification.php`, `src/Contract/ReturnAwareGatewayInterface.php`, `src/Contract/ExpirableGatewayInterface.php`, `src/Contract/VerifiableGatewayInterface.php`, `src/Gateway/StripeGateway.php`, `src/Gateway/StripeSessionReader.php`, `src/Registry/PaymentGatewayRegistry.php`, `src/Management/GatewayHealthCheckProvider.php`, `src/Management/PaymentAlertProvider.php`

**Related skills:** `c975l-payment-checkout` and `c975l-payment-items` in this same bundle; `c975l-config` and `c975l-operations` in `c975l/core-bundle`.

## Adding a provider

Implement `Contract\PaymentGatewayInterface` in a service under your bundle's `Gateway/` folder — autoconfigured, no manual tagging, `PaymentGatewayRegistry` collects it. Then add its slug to the `choices` of the `payment-gateway` config entry, and the site owner picks it from the back-office.

Five methods:

| Method | Answers |
| --- | --- |
| `getSlug()` | the provider's slug, which is also its webhook path segment |
| `isConfigured()` | whether its keys are set |
| `createCheckout(CheckoutRequest)` | a `CheckoutSession` — where to send the customer, and what the provider calls that checkout by |
| `readNotification(Request)` | a `PaymentNotification`, **signature verification included**, or null |
| `getTransactionUrl(string)` | the provider's own back-office url for a transaction |

The webhook url follows the slug: `POST /payment/webhook/<slug>`.

## The three DTOs

```php
new CheckoutRequest($currency, $lines, $successUrl, $cancelUrl, $email, $metadata)
new CheckoutSession($url, $reference = null)
new PaymentNotification($basketId, $gateway, $transactionId = null, $paymentMethod = null, $amount = null)
```

`$lines` is one entry per basket line plus one for the shipping, each `{name, amount, quantity}`, amounts in the currency's **smallest unit**. `$metadata` is what the provider must hand back with its notification.

`CheckoutSession::$reference` is what the provider calls the checkout; it is kept on `Payment.gateway_reference` until the payment settles or the checkout is called off. A provider naming its checkouts nothing leaves it null.

**`PaymentNotification::$amount` is the amount the provider actually charged**, and the checkout verifies it against the basket. A gateway that cannot report it leaves it null — but report it if the provider gives it to you.

## The three optional interfaces

Each is kept apart precisely so adding one never breaks a provider that already exists. A gateway implementing none of them stays perfectly valid.

| Interface | Method | Without it |
| --- | --- | --- |
| `VerifiableGatewayInterface` | `verifyCredentials(): ?string` | the health check reports the gateway as skipped |
| `ReturnAwareGatewayInterface` | `readReturn(Request): ?PaymentNotification` | the webhook alone confirms, a moment later |
| `ExpirableGatewayInterface` | `expireCheckout(string $reference): void` | a stale checkout stays payable and is refused on its amount |

`verifyCredentials()` returns null when the keys authenticate, the provider's own reason when they do not. It is called from `c975l:health-check:run` **only** — never from a controller, it reaches a third party and no dashboard page may block on that. `PaymentAlertProvider` reads the config and can only tell a *missing* key from a present one; a revoked or mistyped key reads exactly like a working one, which is what the health check exists for.

`readReturn()` confirms the customer's return **with the provider itself** rather than believing the url they arrived on.

## Stripe, as the reference

`Gateway\StripeGateway` is the only class in the bundle importing `Stripe\*` — keep it that way. `Gateway\StripeSessionReader` is split out of it: it decides, **on the payload alone**, whether Stripe reports the money as arrived. Both paths — the signed webhook event and the session fetched back on return — read a session through it, so the two cannot drift into disagreeing about what counts as paid.

Two things that bite:

- **`checkout.session.completed` does not mean paid.** An asynchronous method (SEPA debit, bank transfer) completes the session while the money is still on its way. Only the session's own `payment_status` decides. Which methods a site offers is a setting of its Stripe dashboard that nothing in the code constrains.
- **Reading a session does not need its `PaymentIntent`.** The session already carries the transaction id and the payment methods — fetching the intent is a second round-trip for nothing.

The `success_url` carries Stripe's `{CHECKOUT_SESSION_ID}` placeholder, which is how the return path re-reads the session.

## Keys and test mode

Six config keys: `payment-gateway`, `payment-test-mode`, and per provider a live/test pair — `stripe-secret` / `stripe-secret-test` and `stripe-webhook-secret` / `stripe-webhook-secret-test`. The gateway picks the pair from `payment-test-mode`, never from the shape or contents of the key itself.

`PaymentGatewayRegistry::getActiveOrNull()` answers null rather than throwing when the configured provider holds no usable key; `BasketService::validate()` turns that into a `PaymentUnavailableException` the basket page states, instead of the provider's own 500.

## Do not

- **Do not import the provider's SDK outside its own `Gateway/` class.**
- **Do not skip signature verification** in `readNotification()`.
- **Do not treat a "session completed" event as payment received** — read the payment status.
- **Do not call `verifyCredentials()` from a controller** or a dashboard render.
- **Do not answer the provider with the exception's own message** — it goes back over the wire.
- **Do not add a method to `PaymentGatewayInterface`** for a capability one provider has; add an optional interface.
- **Do not read test mode from the secret key's contents.**
- **Do not fetch more from the provider than the answer needs.**
