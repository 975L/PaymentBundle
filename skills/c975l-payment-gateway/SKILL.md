---
name: c975l-payment-gateway
description: "Use this skill when adding, configuring or debugging a payment provider in a c975L site — the gateway contract, the Stripe and Revolut implementations, webhook signatures, the three optional interfaces, the test keys and the gateway health check. Covers what belongs behind the contract and what must never leak out of it. Triggers on: PaymentGatewayInterface, PaymentGatewayRegistry, getOffered, payment_gateways, StripeGateway, StripeSessionReader, RevolutGateway, RevolutOrderReader, CheckoutRequest, CheckoutSession, PaymentNotification, ReturnAwareGatewayInterface, ExpirableGatewayInterface, VerifiableGatewayInterface, getCheckoutDomains, CheckoutCspSubscriber, GatewayHealthCheckProvider, PaymentAlertProvider, payment-gateway, stripe-secret, stripe-webhook-secret, revolut-secret, revolut-webhook-secret, RevolutWebhookCommand, GatewayExtension, checkout.session.completed, payment_status, ORDER_COMPLETED, Revolut-Signature."
---

# c975L PaymentBundle — payment providers

> Everything a payment provider knows — keys, signatures, event shapes, API calls — stays behind one interface. The rest of the bundle only ever sees a url to redirect to and a `PaymentNotification`.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Contract/PaymentGatewayInterface.php`, `src/Contract/CheckoutRequest.php`, `src/Contract/CheckoutSession.php`, `src/Contract/PaymentNotification.php`, `src/Contract/ReturnAwareGatewayInterface.php`, `src/Contract/ExpirableGatewayInterface.php`, `src/Contract/VerifiableGatewayInterface.php`, `src/Gateway/StripeGateway.php`, `src/Gateway/StripeSessionReader.php`, `src/Gateway/RevolutGateway.php`, `src/Gateway/RevolutOrderReader.php`, `src/Registry/PaymentGatewayRegistry.php`, `src/Twig/GatewayExtension.php`, `src/Command/RevolutWebhookCommand.php`, `src/Management/GatewayHealthCheckProvider.php`, `src/Management/PaymentAlertProvider.php`

**Related skills:** `c975l-payment-checkout` and `c975l-payment-items` in this same bundle; `c975l-config` and `c975l-operations` in `c975l/core-bundle`.

## Adding a provider

Implement `Contract\PaymentGatewayInterface` in a service under your bundle's `Gateway/` folder — autoconfigured, no manual tagging, `PaymentGatewayRegistry` collects it. Then add its slug to the `choices` of the `payment-gateway` config entry, and a `label.gateway_<slug>` key to the `payment` domain — that is its name in the customer's chooser.

Six methods:

| Method | Answers |
| --- | --- |
| `getSlug()` | the provider's slug, which is also its webhook path segment |
| `isConfigured()` | whether its keys are set |
| `createCheckout(CheckoutRequest)` | a `CheckoutSession` — where to send the customer, and what the provider calls that checkout by |
| `readNotification(Request)` | a `PaymentNotification`, **signature verification included**, or null |
| `getTransactionUrl(string)` | the provider's own back-office url for a transaction |
| `getCheckoutDomains()` | the hosts the payer's browser is sent to, as a CSP source list writes them |

The webhook url follows the slug: `POST /payment/webhook/<slug>`.

**`getCheckoutDomains()` is what keeps the site's CSP from blocking the payment.** Validating a basket is a form post
answered by a redirection to the checkout, and `form-action` is checked on the whole redirection chain of a form
navigation — a policy limited to `'self'` therefore takes the order and loses the customer on the way to paying for it.
`EventSubscriber\CheckoutCspSubscriber` completes that directive on the way out with what the *active* gateway answers
here, so nothing is written in the site's own yaml. A provider charging without ever navigating away answers `[]`.

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
| `ReturnAwareGatewayInterface` | `readReturn(Request, ?string $reference): ?PaymentNotification` | the webhook alone confirms, a moment later |
| `ExpirableGatewayInterface` | `expireCheckout(string $reference): void` | a stale checkout stays payable and is refused on its amount |

`verifyCredentials()` returns null when the keys authenticate, the provider's own reason when they do not. It is called from `c975l:health-check:run` **only** — never from a controller, it reaches a third party and no dashboard page may block on that. `PaymentAlertProvider` reads the config and can only tell a *missing* key from a present one; a revoked or mistyped key reads exactly like a working one, which is what the health check exists for.

`readReturn()` confirms the customer's return **with the provider itself** rather than believing the url they arrived on. Its `$reference` is what the provider called the checkout, kept on the payment since — a provider writing nothing of its own into the return url has that and nothing else to look the payment up by.

## Stripe, as the reference

`Gateway\StripeGateway` is the only class in the bundle importing `Stripe\*` — keep it that way. `Gateway\StripeSessionReader` is split out of it: it decides, **on the payload alone**, whether Stripe reports the money as arrived. Both paths — the signed webhook event and the session fetched back on return — read a session through it, so the two cannot drift into disagreeing about what counts as paid.

Two things that bite:

- **`checkout.session.completed` does not mean paid.** An asynchronous method (SEPA debit, bank transfer) completes the session while the money is still on its way. Only the session's own `payment_status` decides. Which methods a site offers is a setting of its Stripe dashboard that nothing in the code constrains.
- **Reading a session does not need its `PaymentIntent`.** The session already carries the transaction id and the payment methods — fetching the intent is a second round-trip for nothing.

The `success_url` carries Stripe's `{CHECKOUT_SESSION_ID}` placeholder, which is how the return path re-reads the session.

## Revolut, as the second one

`Gateway\RevolutGateway` calls the Merchant API over `HttpClientInterface` — Revolut ships no PHP SDK — and `Gateway\RevolutOrderReader` is its counterpart to `StripeSessionReader`: it decides on the payload alone, and both paths read an order through it.

What differs from Stripe, and why:

- **The webhook event is thin.** `{event, order_id, merchant_order_ext_ref}` and nothing else — no state, no amount. So `readNotification()` verifies the signature, filters `ORDER_COMPLETED`, then **reads the order back** from the API. What settles a basket is what Revolut answers, not what it posted.
- **The basket travels as `merchant_order_data.reference`**, which comes back as `merchant_order_ext_ref`. Revolut hands no metadata of the site's back.
- **The signature is hand-rolled**: HMAC-SHA256 over `v1.{timestamp}.{raw body}`, compared against the `Revolut-Signature` header as `v1={hash}`, with `hash_equals`. The header carries **several** signatures while a secret is being rotated, and either matching is enough. The `Revolut-Request-Timestamp` header is in **milliseconds** and is enforced within five minutes — a signature stays valid for as long as the secret does, so only the timestamp stops a replay.
- **`Revolut-Api-Version` is mandatory** and the answer's shape follows it. The gateway pins `2024-05-01`; the reader takes the amount from `amount` or `order_amount.value` so a version bump does not silently null it.
- **`state` must be `completed`.** `authorised` means the funds are only held for a capture nobody asked for.
- **The sandbox is a separate space** (`sandbox-merchant.revolut.com`) with its own keys and its own orders.
- **`getTransactionUrl()` answers null** — Revolut publishes no stable address for an order in its portal, and a guessed link is worse than none.
- **The webhook cannot be declared from Revolut's dashboard**, only by `POST /api/webhooks`. That is why the bundle ships a back-office procedure (`config/procedures.json`, `Management\ProcedureProvider`) rather than a line in the README alone.

## Who is offered, and who is charged

**A provider is offered to the customer as soon as `isConfigured()` answers yes for the mode in use.** There is no second list beside the keys, so the two cannot disagree — a shop opens a provider by storing its keys and closes it by clearing them.

| Call | Answers |
| --- | --- |
| `getOffered()` | every configured provider, keyed by slug, the default first — what the basket draws and what the CSP names |
| `getActive()` / `getActiveOrNull()` | the provider `payment-gateway` names: the one pre-selected, and the one an order nobody stands in front of is charged with |

Rules that follow, and each is a bug if broken:

- **Validate the submitted slug against `getOffered()`.** It arrives off a form. `BasketService::resolveGateway()` falls back to the default, then to the first offered — it never charges through a slug it was merely handed.
- **Ask the provider that took the money, not the default.** `confirmWithGateway()` and `expireCheckout()` both resolve from `Payment::getGateway()`: the default is not who charged an order once the customer picks, and never was for an order settled before the shop changed provider.
- **The CSP names every offered provider**, not the default alone — the customer picks after the page is drawn.
- **Templates read `payment_gateways()`** (`Twig\GatewayExtension`), never `config('payment-gateway')`, which only ever named one.
- **The health check walks `getOffered()`**, one row per provider; the dashboard alert fires when the list is empty, or when `payment-gateway` names a provider no bundle registers. `Management\PaymentAlertProvider` also raises one alert of its own that has nothing to do with a provider — an empty `shop-email-bcc`, see `c975l-payment-checkout`.

## Keys and test mode

`payment-gateway`, `payment-test-mode`, and per provider a live/test pair for the api key and for the webhook secret — `stripe-secret` / `stripe-secret-test`, `stripe-webhook-secret` / `stripe-webhook-secret-test`, and the same four under `revolut-`. The gateway picks the pair from `payment-test-mode`, never from the shape or contents of the key itself.

`PaymentGatewayRegistry::getActiveOrNull()` answers null rather than throwing when the configured provider holds no usable key; `BasketService::validate()` turns that into a `PaymentUnavailableException` the basket page states, instead of the provider's own 500.

## Do not

- **Do not import the provider's SDK outside its own `Gateway/` class.**
- **Do not skip signature verification** in `readNotification()`.
- **Do not treat a "session completed" event as payment received** — read the payment status.
- **Do not call `verifyCredentials()` from a controller** or a dashboard render.
- **Do not answer the provider with the exception's own message** — it goes back over the wire.
- **Do not add a method to `PaymentGatewayInterface`** for a capability one provider has; add an optional interface.
- **Do not read test mode from the secret key's contents.**
- **Do not trust a thin webhook payload** — if the event carries no state of its own, read the order back before delivering anything.
- **Do not guess a provider's back-office url** for a transaction; answer null.
- **Do not read `config('payment-gateway')` to know who the shop is paid through** — it names the default, not the list.
- **Do not charge, confirm or expire through the *active* gateway** when the order names its own on `Payment.gateway`.
- **Do not fetch more from the provider than the answer needs.**
