# PaymentBundle

Symfony bundle providing the generic basket and checkout engine plus Stripe payments for the c975L ecosystem — any bundle plugs its own sellable items in through `BasketItemProviderInterface`, without PaymentBundle ever knowing their concrete entity classes.

[![GitHub](https://img.shields.io/github/license/975L/PaymentBundle)](https://github.com/975L/PaymentBundle/blob/main/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/payment-bundle)](https://packagist.org/packages/c975l/payment-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/payment-bundle)](https://packagist.org/packages/c975l/payment-bundle)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/44b3a42b6a63445a8d9da90769659b13)](https://app.codacy.com/gh/975L/PaymentBundle/dashboard)

---

## Why PaymentBundle

![PaymentBundle](.github/images/PaymentBundle.svg)

Add PaymentBundle on top of the shared [UiBundle](https://github.com/975L/UiBundle) + [ConfigBundle](https://github.com/975L/ConfigBundle) foundation to get a generic Basket/checkout engine and Stripe payments — no dependency on ShopBundle, CrowdfundingBundle or any other satellite bundle, so it drops into any c975L site that needs checkout. Satellite bundles plug their own sellable items into the basket via `BasketItemProviderInterface`, without PaymentBundle ever knowing their concrete entity classes; [ShopBundle](https://github.com/975L/ShopBundle) and [CrowdfundingBundle](https://github.com/975L/CrowdfundingBundle) both rest on this foundation instead of shipping their own checkout.

---

> **TL;DR** — The generic Basket/checkout engine and Stripe payments for the ecosystem. It never knows the concrete entity classes it sells: a satellite bundle (ShopBundle for products, CrowdfundingBundle for campaigns…) implements `BasketItemProviderInterface` and its items become buyable. Adding a new kind of sellable item means implementing that one interface.

## Contents

- **Setup** — [requirements](#requirements) · [installation](#installation) · [assets](#install-assets) · [Stripe webhook](#configure-stripe-webhook) · [Stimulus controllers](#register-stimulus-controllers)
- **Using it** — [test mode](#test-mode) · [adding a payment provider](#adding-a-payment-provider) · [adding a new kind of sellable item](#adding-a-new-kind-of-sellable-item) · [verifying a gateway's credentials](#verifying-a-gateways-credentials) · [offering bought files in the customer area](#offering-bought-files-in-the-customer-area) · [routes](#public-routes) · [commands](#commands) · [AI agent skills](#ai-agent-skills)

## Features

- Basket (add/remove items, coordinates form, validation)
- Stripe Checkout integration with webhook handling, behind a `PaymentGatewayInterface` a second provider is added
  through - nothing outside `StripeGateway` imports `Stripe\*`
- Test mode switched from the dashboard, charging with the provider's test keys and warning the customer
- Generic `BasketItemProviderInterface` extension point — a satellite bundle registers a service implementing it
  to plug a new kind of sellable item into the basket (pricing, stock validation, content flags, pre/post-payment
  hooks), auto-collected via ConfigBundle's `TaggedInterfacePass` (no compiler pass to write in the satellite)
- Optional `BasketRecommendationProviderInterface` for cross-sell recommendations on the basket page
- Customer area: a logged-in buyer reads their own order history at `/account/orders`, each order showing its
  tracking, its lines and — through the optional `BasketDownloadProviderInterface` — the files they bought,
  downloadable again however long ago the emailed link expired
- EasyAdmin CRUD for Basket and Payment, both exported as SQL/CSV/JSON from their index, the basket's security token excluded
- Status report and health check contributions: the orders left to ship and the payments started and never
  confirmed land in `/status/report`, and `c975l:health-check:run` asks the active gateway whether its keys still
  authenticate — a revoked or mistyped key reads from the config exactly like a working one
- Its own stylesheet and icons, auto-registered through UiBundle's `BundleStylesheetProviderInterface` — the
  basket renders the same with or without ShopBundle installed

---

## Requirements

- PHP >= 8.4
- [c975L/CoreBundle](https://github.com/975L/CoreBundle) (ships ConfigBundle and UiBundle)
- Stripe PHP SDK (`stripe/stripe-php`)
- EasyAdmin

---

## Installation

```bash
composer require c975l/payment-bundle
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console c975l:config:load-all
```

Add to `config/routes.yaml`:

```yaml
c975_l_payment:
    resource: "@c975LPaymentBundle/src/Controller/"
    type: attribute
```

### Install assets

```bash
php bin/console assets:install --symlink
```

This exposes the bundle's compiled stylesheet at `public/bundles/c975lpayment/css/styles.min.css` and the basket
icons it draws.

### Configure Stripe webhook

1. Sign in to your [Stripe Dashboard](https://dashboard.stripe.com/)
2. Go to **Developers > Webhooks** and click **Add endpoint**
3. Select the `checkout.session.completed` event
4. Enter `https://your-website.com/payment/webhook/stripe` as the endpoint URL
5. Copy the signing secret and store it via the ConfigBundle dashboard (`stripe-webhook-secret`)

Do it twice, once in each Stripe space: the test dashboard has its own endpoint and its own signing secret, which
goes to `stripe-webhook-secret-test`. The historical `/shop/stripe/webhook` url still answers, so an endpoint
already declared at Stripe has nothing to change.

### Register Stimulus controllers

Nothing to register by hand. The bundle ships `controllers.js`, the entrypoint starting its own Stimulus app for
the `basket` controller - the one every add button, quantity control and basket bar is mounted on, in this bundle
as in the ones plugging into it. The controller itself is imported on demand, for a document actually carrying a
`data-controller="basket"`, so the pages holding no basket at all download nothing of it. The entrypoint is
auto-registered through UiBundle's script registry, as long as your layout
renders `{{ importmap(['app']|merge(bundle_scripts())) }}`, and its `importmap.php` entry is written by
`ImportmapProvider` the first time you `composer update` after installing the bundle -
`php bin/console c975l:config:check-importmap` reports it if it is missing.

---

## Usage

### Public routes

| Route | URL | Description |
| --- | --- | --- |
| `basket_display` | `/shop/basket/display` | The basket page: its lines, the coordinates form and, if a provider offers any, the recommendations |
| `basket_add` | `/shop/basket` (POST) | Adds an item, the kind and its payload read from the request by the provider owning it |
| `basket_product_delete` | `/shop/basket/delete` (DELETE) | Takes one line out |
| `basket_delete` | `/shop/basket` (DELETE) | Empties the basket |
| `basket_json` | `/shop/basket/json` | The basket's total and quantity, for the header's counter |
| `basket_validate` | `/shop/basket/validate` | Numbers the basket and sends the customer to the provider - or straight to `basket_paid` when there is nothing to pay |
| `basket_paid` | `/shop/basket/paid/{number}/{securityToken}` | Where the customer comes back. Confirms the payment **with the provider** before anything is delivered |
| `customer_orders` | `/account/orders` | A signed-in buyer's own orders, newest first |
| `customer_order` | `/account/orders/{number}` | One order: its tracking, its lines and the files it bought |
| `items_shipped` | `/shop/basket/items-shipped/{number}/{type}` | Marks a kind of item as shipped and emails the customer |
| `payment_webhook` | `/payment/webhook/{gateway}` (POST) | Where a provider announces a payment - **the endpoint to declare in its dashboard** |
| `stripe_webhook` | `/shop/stripe/webhook` (POST) | The endpoint ShopBundle served up to its v1.12, kept under the same name and path so a shop upgrading does not have to touch its Stripe dashboard the same day |

`basket_paid` carries a security token generated with the order number: it is what lets a customer reach their
own order page and nobody else's. **Reaching that url is never what delivers an order** — see
[Stripe webhook](#configure-stripe-webhook) and the [UPGRADE notes](UPGRADE.md), the payment being confirmed
with the provider on both paths.

`customer_orders` and `customer_order` are behind `IS_AUTHENTICATED_FULLY`, and only list baskets carrying a
`user`. An order that is not the asking user's own answers **404, not 403**: order numbers follow one another,
and a 403 would confirm which ones exist.

---

## Commands

| Command | Description |
| --- | --- |
| `php bin/console c975l:shop:baskets:delete` | Deletes unvalidated baskets older than 14 days |

Run `c975l:shop:baskets:delete` daily via the Symfony Scheduler or a cron job.

---

## Test mode

`payment-test-mode` is what tells a real order from a rehearsal, and it is switched from the dashboard's tile
rather than edited by hand. While it is on:

- the provider is called with its test keys (`stripe-secret-test`, `stripe-webhook-secret-test`) instead of the
  live ones, so nobody is charged
- every order number carries a `TEST-` prefix, and the basket pages carry a banner saying so
- a payment links to the provider's test dashboard from the back-office, where the transaction actually exists

Nothing reads a key to guess any of this: a live key holding the word "test" anywhere used to turn a real shop
into a rehearsal, and a test key not holding it charged nobody while the site claimed it did.

Set both pairs of keys before switching, `isConfigured()` on the gateway answering whether the pair in use is
there at all. A shop whose active pair is missing - or unreadable, a sensitive value being decrypted on the way
out - says so on the dashboard (`PaymentAlertProvider`) and refuses to validate a basket rather than letting the
provider fail in front of the customer. [ShopBundle](https://github.com/975L/ShopBundle) has a test mode of its own, `shop-test-mode`, for a
catalog being filled in; the two are independent and either shows the shop's banner.

---

## Adding a payment provider

Implement `c975L\PaymentBundle\Contract\PaymentGatewayInterface` in a service (autoconfigured, no manual tagging
needed) — see `c975L\PaymentBundle\Gateway\StripeGateway` for the reference implementation. Five methods: the
provider's slug, whether its keys are set, opening a checkout from a `CheckoutRequest` and answering a
`CheckoutSession` (where to send the customer, and what the provider calls that checkout by), reading a webhook
payload into a `PaymentNotification` (signature verification included), and the provider's own back-office url for
a transaction.

Then add the slug to the `choices` of the `payment-gateway` config entry, and the site owner picks it from the
back-office. The webhook url follows the slug: `/payment/webhook/<slug>`.

Three further interfaces are optional, and a gateway implementing none of them stays perfectly valid — each is
kept apart precisely so that adding one never breaks a provider that already exists:

| Interface | What it buys | Without it |
| --- | --- | --- |
| `VerifiableGatewayInterface` | tells a revoked or mistyped key from a working one | the health check reports the gateway as skipped |
| `ReturnAwareGatewayInterface` | confirms the payment when the customer returns, rather than waiting on the webhook | the webhook alone confirms it, a moment later |
| `ExpirableGatewayInterface` | calls off a checkout whose basket has been edited | the stale checkout stays payable and is refused on its amount at delivery |

Nothing else of the bundle knows a provider exists: `BasketService` prices the basket, hands it over as a
`CheckoutRequest` and gets back a `CheckoutSession`.

**The site delivers on a payment the provider confirms, never on the url the customer returns with** — that url is
handed to them before they pay. `BasketService::paid()` refuses to deliver a basket that has something to pay and
no payment recorded as finished, whichever of the two paths reaches it.

---

## Adding a new kind of sellable item

Implement `c975L\PaymentBundle\Contract\BasketItemProviderInterface` in a service (autoconfigured, no manual
tagging needed) — see `c975L\ShopBundle\Service\ProductBasketItemProvider` (kind `product`) or
`c975L\CrowdfundingBundle\Service\CrowdfundingBasketItemProvider` (kind `crowdfunding`) for reference
implementations.

The one thing to get right is the pair of hooks around the payment. A provider **hands over** what it will need
once the basket is delivered, and gets it back verbatim:

```php
// Called once the basket is validated, before the customer is sent to the provider
public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array
{
    // Whatever the checkout form collected that lives nowhere else - return [] when there is nothing
    return ['name' => $requestData['coordinates']['contributorName'] ?? null];
}

// Called once the basket is paid, and once only
public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void
{
    // $checkoutData is exactly what the call above returned
}
```

**Do not keep that data in the session, and do not read the current request here.** `onBasketPaid()` is reached
from the payment provider's webhook as well as from the customer's return, and the webhook is a request from the
provider: it carries no session of that customer. Anything left in the session is lost for everyone who does not
come back to the site before the webhook lands. What is handed over is kept on the basket (`checkout_data`) and
dropped as soon as the basket is delivered or its checkout called off — it carries the customer's own details, it
is no record.

Most kinds have nothing to carry: what was ordered is already on the basket, so `onBasketValidated()` returns `[]`
and `onBasketPaid()` reads `$itemsOfThisKind`. That is what `ProductBasketItemProvider` does.

---

## Verifying a gateway's credentials

A gateway implementing `c975L\PaymentBundle\Contract\VerifiableGatewayInterface` has a single
`verifyCredentials(): ?string` returns null when the configured keys authenticate and the provider's own reason
when they do not. `GatewayHealthCheckProvider` calls it from `c975l:health-check:run` — never from a controller,
it reaches a third party. A gateway that does not implement it stays perfectly valid: its row is reported as
skipped rather than left out. `StripeGateway` implements it by retrieving the account the key belongs to.

---

## Offering bought files in the customer area

The customer area lists a buyer's paid orders on its own. To let them download again what they bought, implement
`c975L\PaymentBundle\Contract\BasketDownloadProviderInterface` in a service (autoconfigured, no manual tagging):

```php
public function getDownloads(Basket $basket): array
{
    // Called for a basket already checked as paid and as belonging to the user asking
    return [
        ['title' => 'The book (PDF)', 'url' => '/download/a-token', 'size' => 1048576],
    ];
}
```

Return `[]` for a basket holding nothing of your kind. With no provider installed, the downloads section is left
out of the page entirely rather than drawn empty. This bundle never learns what a product is: it hands over the
basket and renders the links that come back.

Only baskets carrying a `user` are listed. Matching on the email address instead would hand someone the orders of
whoever used that address before them, the moment they register an account with it.

## AI agent skills

The package ships three skills of its own, written for the coding agent of the site installing this bundle
rather than for someone modifying it. Point your agent at them:

```text
vendor/c975l/payment-bundle/skills/
```

- `c975l-payment-checkout` — the basket, the checkout and what makes an order real: the two paths that
  confirm a payment, and the rule that nothing is ever delivered on the strength of the url the customer
  came back on
- `c975l-payment-gateway` — adding, configuring or debugging a payment provider: the contract, the Stripe
  implementation, the webhook signature, the three optional interfaces and the test keys
- `c975l-payment-items` — plugging a new kind of sellable item in from a satellite bundle: the provider
  contract, the pre/post-payment hooks and the one mistake that loses a customer's data

They hold what an agent gets wrong when left to its own habits — that a setting goes in `config/configs.json`
and not in `.env`, that nothing outside `StripeGateway` may import `Stripe\*`, that pre-payment data is handed
over rather than stashed in the session — alongside the routes, the entities, the config slugs and the
contracts, each named as it actually is in the sources. `tests/SkillsTest.php` fails as soon as one of those
names stops matching the code.

---

> [!TIP]
> If this project **helps you save development time**:
>
> - [**star** it on GitHub](https://github.com/975L/PaymentBundle) — helps others find it
> - [**open an issue**](https://github.com/975L/PaymentBundle/issues/new) to share how you use it — genuinely useful feedback
>
> And if you'd like to support the work directly, the **Sponsor** button at the top of the GitHub page is there for that. Thank you!
