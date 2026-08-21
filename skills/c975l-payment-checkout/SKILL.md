---
name: c975l-payment-checkout
description: "Use this skill when working on the basket or the checkout of a c975L site — adding to and emptying the basket, validating it, sending the customer to the payment provider, confirming the payment, delivering the order, the customer area and the transactional emails. Covers the rule that decides when an order is real, the two paths that confirm a payment, and what must never be trusted. Triggers on: BasketService, Basket entity, Payment entity, basket_display, basket_validate, basket_paid, payment_webhook, PaymentWebhookController, applyNotification, confirmReturn, claimPaid, checkout_data, gateway_reference, payment-test-mode, customer_orders, BasketDownloadProviderInterface, ConfirmOrderMessage, ItemsShippedMessage."
---

# c975L PaymentBundle — the basket and the checkout

> The generic basket and checkout engine: what a visitor puts in a basket, how it is priced and validated, how a payment is confirmed, and when an order becomes real.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Service/BasketService.php`, `src/Entity/Basket.php`, `src/Entity/Payment.php`, `src/Controller/BasketController.php`, `src/Controller/PaymentWebhookController.php`, `src/Controller/CustomerAreaController.php`, `src/Repository/BasketRepository.php`, `src/Email/BasketEmailFactory.php`, `src/MessageHandler/`, `templates/basket/`, `templates/customer/`

**Related skills:** `c975l-payment-gateway` and `c975l-payment-items` in this same bundle; `c975l-config` and `c975l-management` in `c975l/core-bundle`.

## The one rule

**The site delivers on a payment the provider confirms, never on the url the customer returns with.**

`/shop/basket/paid/{number}/{securityToken}` is handed to the customer *before* they pay — they can open it, bookmark it or share it without ever having been charged. `BasketService::paid()` therefore refuses to deliver a basket that has something to pay and no `Payment` recorded as finished. Reaching that page proves nothing.

Delivering means: decrementing stock, issuing downloads, running every provider's `onBasketPaid()` and sending the confirmation email. It happens **once**, whichever path gets there first.

## The two confirmation paths

Both end up in the same `paid()`, and each covers the other's blind spot:

| Path | Entry point | Covers |
| --- | --- | --- |
| Webhook | `POST /payment/webhook/{gateway}` → `applyNotification()` | the customer who pays and never comes back — closed tab, mobile, 3-D Secure |
| Return | `/shop/basket/paid/...` → `confirmReturn()` | the site whose webhook endpoint is misconfigured |

The return path re-reads the checkout **from the provider** (`ReturnAwareGatewayInterface::readReturn()`); it never believes the url. A gateway that does not implement it simply waits on the webhook.

**`BasketRepository::claimPaid()` is what makes this safe.** It moves a basket from `validated` to `paid` in one conditional UPDATE. Both paths confirm the same payment within the same second, both would read `validated`, and both would deliver — double stock decrement, two confirmation emails. Whoever loses the claim does nothing.

**Always deliver through `paid()`, and always claim before delivering.** Never write `$basket->setStatus('paid')` and then send an email.

## Basket statuses

`new` → `validated` → `paid` → `shipped`. Only `paid` and `shipped` are orders; `BasketRepository::findPaidByUser()` lists those two and nothing else — a basket still `new` or `validated` is a checkout that never completed.

A basket **edited after validation goes back to `new`**: `addItem()` and `deleteItem()` reopen it, and the checkout already opened at the provider is called off (`ExpirableGatewayInterface`). A provider's checkout session stays payable for hours and the customer still has it in a tab; without expiring it the site can only refuse the payment afterwards, by which point they are charged and take delivery of nothing.

An order the session still names — paid, customer never came back — is neither added to nor taken from: the visitor starts a new basket.

## The amount is checked

`PaymentNotification` carries what the provider actually charged, and `applyNotification()` drops a notification whose amount does not match what the basket now holds. Do not compare the charge with the `Payment` row instead: both are written at the same moment and always agree.

A notification naming an unknown basket is **logged and dropped**, never raised — a 500 has the provider replay it for days.

## Content flags

`Basket::$contentflags` is a bitmask, contributed per line by each item provider:

`CONTENT_FLAG_DIGITAL` 1 · `CONTENT_FLAG_PHYSICAL` 2 · `CONTENT_FLAG_CF_SHIPPING` 4 · `CONTENT_FLAG_CF_DIGITAL` 8 · `CONTENT_FLAG_SERVICE` 16

It decides whether an order needs shipping, whether it is delivered by its message handler alone, and what the order page shows. Read it off `c975L\PaymentBundle\Entity\Basket` — the class lives here, not in ShopBundle.

## Test mode

`payment-test-mode` is switched from the dashboard tile, never edited by hand. While on: the provider's test keys are used, the order number carries a `TEST-` prefix, the basket pages carry `Basket:TestMode`, and the payment CRUD stops linking to the provider's live dashboard. The prefix follows the setting, **not** the word "test" in the secret key.

## Emails

The four transactional emails (`confirm_order`, `download_information`, `items_shipped`, `counterparts_shipped`) go through UiBundle's `EmailService` with `wrapLayout: true` — their bodies carry no `extends`, the branded layout coming from `EmailLayoutRegistry`. `Email\BasketEmailFactory` builds the shared `EmailSendRequest`, its envelope read from the six `shop-email-*` keys, a blank one falling back on the site-wide `email-*` address.

The handlers **throw** when a send fails, so Messenger retries rather than dropping the email silently.

## Customer area

`/account/orders` lists a logged-in buyer's paid orders, `/account/orders/{number}` shows one with its tracking, its lines and its downloads. **Only baskets carrying a `user` are listed** — matching on the email address would hand someone the orders of whoever used that address before them. An order that is not the asking user's own answers **404, not 403**: order numbers run in sequence.

Downloads come from `BasketDownloadProviderInterface`; with nothing implementing it the section is left out rather than drawn empty.

## Do not

- **Do not deliver anything from the return url** without a payment recorded as finished.
- **Do not deliver without `claimPaid()`** — the webhook and the return race.
- **Do not raise on a notification naming an unknown basket.** Log it and drop it.
- **Do not trust the amount the basket holds** as proof of what was charged.
- **Do not leave a validated basket editable** without expiring its checkout.
- **Do not read the session in anything reachable from the webhook** — it carries none.
- **Do not answer 403** for another user's order; 404 is the answer.
- **Do not read `payment-test-mode` from the secret key's contents.**
- **Do not send a basket email outside `BasketEmailFactory`**, nor swallow a send failure.
