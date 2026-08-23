# PaymentBundle

Symfony bundle providing the generic basket and checkout engine plus Stripe and Revolut payments for the c975L ecosystem — any bundle plugs its own sellable items in through `BasketItemProviderInterface`, without PaymentBundle ever knowing their concrete entity classes.

[![GitHub](https://img.shields.io/github/license/975L/PaymentBundle)](https://github.com/975L/PaymentBundle/blob/main/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/payment-bundle)](https://packagist.org/packages/c975l/payment-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/payment-bundle)](https://packagist.org/packages/c975l/payment-bundle)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/44b3a42b6a63445a8d9da90769659b13)](https://app.codacy.com/gh/975L/PaymentBundle/dashboard)

---

## Why PaymentBundle

![PaymentBundle](.github/images/PaymentBundle.svg)

Add PaymentBundle on top of the shared [UiBundle](https://github.com/975L/UiBundle) + [ConfigBundle](https://github.com/975L/ConfigBundle) foundation to get a generic Basket/checkout engine and Stripe or Revolut payments — no dependency on ShopBundle, CrowdfundingBundle or any other satellite bundle, so it drops into any c975L site that needs checkout. Satellite bundles plug their own sellable items into the basket via `BasketItemProviderInterface`, without PaymentBundle ever knowing their concrete entity classes; [ShopBundle](https://github.com/975L/ShopBundle) and [CrowdfundingBundle](https://github.com/975L/CrowdfundingBundle) both rest on this foundation instead of shipping their own checkout.

---

> **TL;DR** — The generic Basket/checkout engine, and Stripe or Revolut payments, for the ecosystem. It never knows the concrete entity classes it sells: a satellite bundle (ShopBundle for products, CrowdfundingBundle for campaigns…) implements `BasketItemProviderInterface` and its items become buyable. Adding a new kind of sellable item means implementing that one interface.

## Contents

- **Setup** — [requirements](#requirements) · [installation](#installation) · [assets](#install-assets) · [Stripe webhook](#configure-stripe-webhook) · [Revolut keys and webhook](#configure-revolut) · [Stimulus controllers](#register-stimulus-controllers)
- **Using it** — [test mode](#test-mode) · [adding a payment provider](#adding-a-payment-provider) · [adding a new kind of sellable item](#adding-a-new-kind-of-sellable-item) · [verifying a gateway's credentials](#verifying-a-gateways-credentials) · [offering bought files in the customer area](#offering-bought-files-in-the-customer-area) · [gating a media behind a purchase](#gating-a-media-behind-a-purchase) · [offering several providers](#offering-several-providers) · [block kinds](#block-kinds) · [VAT](#vat) · [payment links](#payment-links) · [routes](#public-routes) · [commands](#commands) · [AI agent skills](#ai-agent-skills)

## Features

- Basket (add/remove items, coordinates form, validation)
- Two providers shipped behind one `PaymentGatewayInterface`: **Stripe** Checkout and **Revolut** Merchant API,
  each with its own webhook handling and signature verification - nothing outside `StripeGateway` imports
  `Stripe\*`, and nothing outside `RevolutGateway` knows Revolut's shapes
- **The customer picks who they pay through** when the shop offers more than one: a provider is offered as soon as
  its keys are filled in, so a second one is opened by storing its keys and closed by clearing them
- Test mode switched from the dashboard, charging with the provider's test keys and warning the customer
- Generic `BasketItemProviderInterface` extension point — a satellite bundle registers a service implementing it
  to plug a new kind of sellable item into the basket (pricing, stock validation, content flags, pre/post-payment
  hooks), auto-collected via ConfigBundle's `TaggedInterfacePass` (no compiler pass to write in the satellite)
- Optional `BasketRecommendationProviderInterface` for cross-sell recommendations on the basket page
- Customer area: a logged-in buyer reads their own order history at `/account/orders`, each order showing its
  tracking, its lines and — through the optional `BasketDownloadProviderInterface` — the files they bought,
  the very links their email carries and for exactly as long, each shown with the date it stops working
- `BasketRepository::hasPaidFor()`, the one question a paywall asks: has this buyer paid for this item, in whatever
  order and however long ago - so a bundle showing a paid photo, video or chapter gates it on the orders themselves
  rather than keeping a right of its own beside them
- EasyAdmin CRUD for Basket and Payment, both exported as SQL/CSV/JSON from their index, the basket's security token excluded
- Status report and health check contributions: the orders left to ship and the payments started and never
  confirmed land in `/status/report`, and `c975l:health-check:run` asks the active gateway whether its keys still
  authenticate — a revoked or mistyped key reads from the config exactly like a working one
- Its own stylesheet and icons, auto-registered through UiBundle's `BundleStylesheetProviderInterface` — the
  basket renders the same with or without ShopBundle installed
- A `payment_shipping` block kind stating what delivery costs, its amounts read from the configuration
- Promotional codes and gift cards through one basket field, an EasyAdmin CRUD for each, a card being minted
  either by a purchase or by hand from the back-office
- A gift card is drawn as a card: a flip card in the ID-1 format, its visual copied onto it at issuance, and a
  page of its own the buyer forwards to whoever the card is for - the code hidden under a panel until it is asked
  for
- Shared payment: an order is frozen and handed over as a link, so somebody else settles it without ever seeing
  who it is for
- Payment links: the shop types a label and an amount in the back-office and gets an address to send, for
  everything the catalogue does not sell - a deposit, a repair, an invoice

---

## Requirements

- PHP >= 8.4
- [c975L/CoreBundle](https://github.com/975L/CoreBundle) (ships ConfigBundle and UiBundle)
- Stripe PHP SDK (`stripe/stripe-php`)
- Symfony HttpClient (`symfony/http-client`), which is what the Revolut gateway calls the Merchant API over
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

### Configure Revolut

Everything is fetched at Revolut and copied into the ConfigBundle dashboard — and the same steps are written out
as a back-office procedure (**Management → Procedures**), for the shopkeeper who is not the one reading this.

Revolut keeps **two separate accounts** where Stripe keeps two spaces of one: the sandbox is opened at
`sandbox-business.revolut.com` and shares nothing with the live account — not its keys, not its orders, not its
webhook. Every step below is therefore done twice, once per account.

1. In the [Revolut Business dashboard](https://business.revolut.com), go to **Merchant > APIs > Merchant API >
   API Keys** and generate the **Production API Secret key**; store it as `revolut-secret`
2. Do the same on the [sandbox dashboard](https://sandbox-business.revolut.com) and store its key as
   `revolut-secret-test`
3. Declare the webhook, once per account. **Revolut offers no screen for it** and answers its signing secret
   once, so the bundle ships the call as a command:

   ```bash
   # payment test mode ON in the back-office → declares the sandbox webhook, stores revolut-webhook-secret-test
   php bin/console c975l:payment:revolut:webhook

   # payment test mode OFF → declares the live webhook, stores revolut-webhook-secret
   php bin/console c975l:payment:revolut:webhook
   ```

   The mode decides which account is called, which key is used and which entry the secret goes to — the command
   says which on its first line, before doing anything. Add `--url=https://example.com` when `site-url` is not the
   address to declare (a site being set up behind another one, a domain about to change).

4. Set `payment-gateway` to `revolut` if you want the basket to pre-select it. Revolut is offered to the customer
   as soon as its keys are stored, whether it is the pre-selected one or not — see
   [offering several providers](#offering-several-providers)

**It runs once per account, and stops itself if you forget.** Revolut adds a second webhook on the same url
rather than replacing the first — both then deliver every event, only the one whose secret is stored here is
accepted, and Revolut retries the refused one three times. So the command reads what is already declared for
that endpoint and refuses to stack on it; `--replace` takes the old one down before declaring a new one.

Doing step 3 by hand instead, for one account:

```bash
curl -X POST https://merchant.revolut.com/api/webhooks \
    -H "Authorization: Bearer YOUR_SECRET_KEY" \
    -H "Revolut-Api-Version: 2024-05-01" \
    -H "Content-Type: application/json" \
    -d '{"url": "https://your-website.com/payment/webhook/revolut", "events": ["ORDER_COMPLETED"]}'
```

The answer carries a `signing_secret`, which goes to `revolut-webhook-secret`; the sandbox call is the same one
against `https://sandbox-merchant.revolut.com/api/webhooks`, and its secret goes to `revolut-webhook-secret-test`.

Without the signing secret the site refuses Revolut's notifications and no order is ever marked paid. A secret
mislaid is read back from the webhook's own details rather than by creating the webhook again.

Two differences with Stripe are worth knowing before switching. **Orders link to nothing** in the back-office:
Revolut publishes no stable address for an order in its portal, so the id is shown rather than a link guessed at.
And **the customer who gives up on the checkout page is not sent back** to the basket - Revolut's hosted page
takes a url for the payment that went through and none for the one abandoned.

### Content Security Policy

Nothing to write. Validating a basket posts to the site, which answers with a redirection to the provider's
checkout, and `form-action` is checked on the whole redirection chain of a form navigation - not only on the
form's action. A policy limited to `'self'` therefore lets the order be created, then blocks the customer on their
way to the payment page.

`EventSubscriber\CheckoutCspSubscriber` completes that directive on the way out, with the hosts the *active*
provider declares through `PaymentGatewayInterface::getCheckoutDomains()` - `checkout.stripe.com` for the one
shipped here. Picking another provider in the back-office moves the policy with it, where a line written in your
own yaml would stay behind.

It only ever completes a policy your site already serves, and only its `form-action`: a site serving no CSP is
given none, and a policy not declaring that directive restricts no form submission in the first place. Nothing
else of the provider's recommended policy is needed either - this bundle redirects to the checkout, it never
embeds the provider's own script.

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
| `basket_code_apply` | `/shop/basket/code` (POST) | Applies the one code the basket field takes, a promotional code or a gift card |
| `basket_code_remove` | `/shop/basket/code` (DELETE) | Takes the code back off |
| `basket_shared` | `/shop/basket/shared/{number}/{securityToken}` | The customer's own page for an order they handed over, carrying the link to send |
| `basket_shared_pay` | `/shop/basket/pay/{number}/{shareToken}` | The payer's page: what is bought and what it costs, **and nothing of who it is for** |
| `basket_shared_paid` | `/shop/basket/pay/{number}/{shareToken}/done` | Where the payer lands, which is never the customer's own order page |
| `basket_short_pay` | `/pay/{shareToken}` | The same payer's page at an address short enough to travel in a text message — a 302 to `basket_shared_pay`, its token read whatever its case |
| `gift_card_display` | `/gift-card/{shareToken}` | The card as whoever it was bought for sees it - no account, `noindex`, `no-store`, and no code in the markup |
| `gift_card_reveal` | `/gift-card/{shareToken}/code` | What the scratch panel asks for, refused on a card taken out of circulation |
| `gift_card_pdf` | `/gift-card/{shareToken}/pdf` | The card as a file to print or keep, its code on it, refused on a card taken out of circulation |
| `customer_orders` | `/account/orders` | A signed-in buyer's own orders, newest first |
| `customer_order` | `/account/orders/{number}` | One order: its tracking, its lines and the files it bought |
| `basket_invoice_pdf` | `/shop/basket/invoice/{number}/{securityToken}` | The order's invoice, linked from the customer's own order page |
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

## Block kinds

The bundle registers one kind with UiBundle's `BlockRegistry`, in the **Shop** category, beside the ones
ShopBundle offers.

| Kind | What it shows | Form type | Template |
| --- | --- | --- | --- |
| `payment_shipping` | What delivery costs and from which amount it is free | `ShippingBlockType` | `blocks/Shipping.html.twig` |

> **Maintenance note:** update this table whenever a kind is added, renamed, or removed in `config/services.yaml`.

The block stores a heading and a free line only: `shop-shipping`, `shop-shipping-free` and `shop-currency` are
read at render time, so the announced amounts follow the configuration and the kind is registered
`cacheable: false`, a cached copy otherwise outliving a change made to them.

The block renders `components/Basket/Shipping.html.twig`, the threshold on its own. On a basket it is
`Basket:FreeShipping` that speaks instead, saying what is left to reach it — `Only 12.00 € more to get free
delivery` — which is what raises an order. `Basket:Items` renders it under the articles and above the totals on an
editable basket only, so neither the order emails nor the coordinates step carry it, and the basket controller
rewrites the line on every add and every removal. It says nothing at all on a
basket holding only files or services, which are never shipped.

---

## Codes and gift cards

The basket page carries **one** code field, and `BasketCodeService` is what tells a promotional code from a gift
card — the customer holds a code, not a category, and asking them which of the two they have is asking them to
know how the shop is built. Spaces and dashes are ignored, so a code read off a card is accepted as it is
written.

**One code per basket, and no stacking**: a card and a promotion applied together is a rule nobody wrote.

`Discount` comes in two kinds, `percentage` and `amount`, and carries its own validity window, its quota and
the minimum it applies from. A `GiftCard` is a balance rather than a reduction: it pays the order down, over as
many orders as it takes, and what is left stays on the card. Both have their own EasyAdmin CRUD, and
`GiftCardService::issue()` mints a card either from a purchase or by hand from the back-office.

A code is spent when the payment is confirmed and nowhere else: a basket abandoned at the payment page burns
neither a quota nor a balance. The free-shipping threshold is weighed against what the basket holds and not
against what is paid, so a code never costs the customer the delivery they had earned.

### The card as an object

A card is not only a code: it is a **card**, and it is drawn as one — `<twig:c975LPayment:GiftCard:Card>` renders
it on UiBundle's flip card in the `credit-card` ratio, its recto carrying the visual, the site's logo, a line of
text and the amount, its verso the very same visual mirrored and faded, and the code. Everything printed on it is
**copied onto the card when it is issued** (`designImage`, `designText`, `scratch`) and never pointed at: the
visual belongs to whichever bundle sold the card (`Contract\GiftCardDesign`, which `ShopBundle` fills from the
product a card was bought on), and a design withdrawn from sale next month must not blank a card somebody still
holds. Nothing here has to know that ShopBundle exists.

### The address the card is seen at

A card is bought for somebody else, who has no account on the site — so each card carries a `shareToken`, and
`/gift-card/{shareToken}` is the page they open. The token is never the code: an address travels through browser
histories, referrers, chat servers and link previews. That page is answered `noindex`, `Referrer-Policy:
no-referrer` and `no-store`, the confirmation email prints its URL beside the code, and the back-office register
shows it for the day a customer loses the message it was sent in.

**The code is not in that page.** With the scratch panel on (the default), the markup holds no code at all: it is
asked for by `/gift-card/{shareToken}/code` once the panel is rubbed off — a link pasted into a chat is fetched by
a robot that reads the markup and runs no script. A card switched off is refused that request while still showing
its visual and its balance. Turn the panel off and the code is printed on the card as it stands, robots included.

**The card is also a file.** `/gift-card/{shareToken}/pdf` draws the two faces on an A4 to cut out at the size of a
bank card, which is what a card posted, slipped into an envelope or simply kept is. A printed card holds no panel to
rub off, so **its code is on it** — and it is refused on a card taken out of circulation, exactly like the panel. The
card's own page and the customer's order page both offer it.

**The card can be sent to its recipient directly.** When the basket carries a gift card, the checkout form asks for
an optional **recipient address** and a word to go with it (`Basket::$giftCardRecipientEmail` /
`$giftCardRecipientMessage`). Filled in, a `gift_card_recipient` e-mail goes out beside the buyer's own
confirmation - the amount, the buyer's message and the card's address, and **no code**: that message travels
through a mailbox that is not the buyer's. Left blank, nothing changes: the buyer gets the addresses in their own
confirmation and forwards them.

It is dispatched as its own `GiftCardRecipientMessage`, so a bounced recipient address never costs the buyer their
confirmation, and the handler re-reads the order rather than trusting the dispatch.

---

## VAT

Prices are held **VAT included**, which is what the customer is charged and what the gateway is handed, so the tax
is taken out of the price rather than added to it. Nothing is stored: `Service\VatCalculator` reads it back from
the basket, and the rate it uses is the one frozen on the line when the item was added — an order answers the same
amounts the day a rate is changed in the back-office.

| Rule | Why |
| --- | --- |
| One entry per rate, sorted | A recap states each rate; an average rate justifies nothing |
| The shipping is shared between the rates, in proportion of what each one weighs | Delivery is taxed at the rate of the goods it carries |
| A promotional code lowers the base | The goods are sold for less |
| A gift card does not | It pays the order, it does not discount it |
| A card sold is left out of the base | Money bought in advance is taxed the day it is spent |
| Rounded per line, the last rate taking what the roundings left | The shares always add up to the amount they came from |

`payment_vat_rate(itemData, kind)` answers the rate **one line** is taxed at, or `null` when it is taxed at none —
what the basket page, the order emails and the invoice state beside the article, a blank cell rather than a `0 %` a
shop would then have to explain. `payment_vat(basket)` answers the whole recap, `{rates: [{rate, total, base,
amount}], amount}`, all amounts in cents. The basket
page states the total as one line the controller keeps up to date, and the order and its email state one line per
rate, once nothing can move any more. A line of the basket also carries its own `totalVat`, written by the
provider through `VatCalculator::included()`.

---

## Invoices

A paid order is numbered, once. `Service\InvoiceService::assign()` is called from `paid()` — the one path the
database has already said runs once per order (`claimPaid()`) — so the sequence follows the orders that were
actually settled and holds **no gap**: an abandoned basket never takes a number out of it.

The number is `{prefix}{year}-{0000}`, the prefix being `shop-invoice-prefix` (`FA` by default). It is drawn from
`payment_invoice_sequence`, one row per year, bumped by the database rather than read, incremented in PHP and
written back — two orders settled in the same second would otherwise be handed the same number, which is the one
thing an invoice sequence must not do. Nothing there goes through the unit of work, so drawing a number never
flushes whatever the caller had pending on the order.

The document itself is drawn on demand and kept nowhere: it says what the order says, and the order is the record.
`shop-invoice-mentions` is printed at its foot — registration numbers, a VAT number, or the article exempting the
shop from charging any. That is written by the shopkeeper, who is the only one who knows their own situation.

| Where | What |
| --- | --- |
| `basket_invoice_pdf` | `/shop/basket/invoice/{number}/{securityToken}`, linked from the customer's own order page |
| Back office | An **Invoice** action on every numbered order, opening the same file |
| E-mail | `payment:invoice`, ticked on whichever templates the shop wants it on (see UiBundle's email attachments) |

**This is a B2C invoice.** Selling to businesses is another matter entirely — a Factur-X document, PDF/A-3 with its
XML inside, sent through an approved platform — and nothing here pretends to be one.

## Address labels

**Address labels** on the orders index draws the sheet to print: the paid orders with something still to post, ten
to an A4 at 105 × 57 mm, the size the stationers sell. Orders with no postal address at all drop out rather than
wasting a label.

Laid out as a table and not as floated boxes, and sized as 45 mm of content plus its padding rather than 57 mm with
the padding inside: floats and `box-sizing` are the two things dompdf and WeasyPrint read differently, and either
mistake prints every label off the paper (`ShippingLabelsSheetTest` guards both).

These are **address** labels, not carrier labels: a tracking barcode is issued by the carrier's own account and API,
which is an integration and not a template.

---

## Emails

Six e-mails follow an order: its confirmation, the two shipping notices, the download links, and the two reminders
for one left unpaid. Every one of them is composed from an `EmailTemplate` an admin edits in the back-office -
this bundle ships no Twig body beside them. `PaymentEmailTemplateProvider` declares all six in French, English and
Spanish, reading each sentence from `translations/payment.*.xlf`; `c975l:ui:email-templates:ensure` seeds the ones
a site does not have yet, and `EmailTemplateRenderer` renders that same declaration if a row is ever deleted, so a
deleted order confirmation is an uneditable one rather than a missing one.

There is therefore exactly one place a sentence lives. Change the default wording in the catalogue and every site
that has not overridden it follows; change it in the back-office and that site alone does, for good.

What an admin edits is the sentences. What the code fills in are the slots: `order_link`, `items`, `counterparts`,
`customer_message`, `gift_cards`, `gift_cards_shared`, `gift_card_message`, `digital_items`, `download_links`,
`delivery` and `account_invitation`, each rendered by a template of
`templates/emails/slots/` and each coming out empty when it has nothing to show - so an order carrying no gift card
prints no blank row. Move them around and put text between them: the composition is the admin's. Taking one out is
the one thing that is refused - an order confirmation without the order's lines confirms nothing - so a slot's kind
and name are locked and a submission that drops one has it put back (see UiBundle, "Data blocks move, they are
never deleted").

**The language is the order's, not the sender's.** `Basket::$locale` is stamped when the order is validated, the one
moment the customer's own request is there to be asked, and `BasketEmailSender` is the only place a basket e-mail is
sent - it switches the translator over for the whole build and puts it back afterwards. Without it a reminder, sent
by a nightly command, and a shipping notice, sent by the shopkeeper's click, would both be written in whatever
language happened to be current. An order taken before this was kept has no locale and is written in the site's own.

The subject is not composed in the back-office: `BasketEmailFactory::buildSubject()` builds it as
`Shop <name> - <what this email is about> - <order number>`, from the `payment` catalogs and the `shop-name` config.

---

## Commands

| Command | Description |
| --- | --- |
| `php bin/console c975l:payment:baskets:retention` | Applies the retention rules below: deletes what nothing keeps any more, archives what is no longer current business |
| `php bin/console c975l:payment:baskets:remind` | Sends the first and second reminder to the customers who validated an order and never paid it |
| `php bin/console c975l:payment:revolut:webhook` | Declares the site's webhook endpoint at Revolut - which has no screen for it - and stores the signing secret it answers once. Once per Revolut account, `--replace` to declare it again |

The first two do not have to be scheduled by the site: `Scheduler\PaymentMaintenanceTaskProvider` declares both, the retention
pass at night and the reminders mid-morning, and a site installing this bundle gets them without listing anything
in its own `MaintenanceSchedule`.

---

## Retention

How long a basket lives is a legal duration and not a shop's preference, so the four are constants of
`Service\BasketRetentionService` rather than settings of each site.

| Basket | Kept | Then |
| --- | --- | --- |
| Never validated (`new`) | 14 days | Deleted, with the recovery cookie that names it |
| Validated, never paid | 30 days | Deleted - not a sale, and by then it holds a full postal address |
| Paid or shipped | 2 years | Archived: `archived` is stamped and the back-office list stops showing it |
| Paid or shipped | 10 years | Deleted, the accounting obligation having run out |

The ten years are those of article L123-22 of the Code de commerce, counted from the close of the accounting
year and not from the order, so the cut is the first of January of that year. The archive is the intermediate
archive the CNIL asks for once the data has served its purpose: a date on the order and a condition on the
back-office list, which is the logical separation it accepts - no second table. Customers still see their own
archived orders in the customer area, the restriction being on the shop's staff and not on them.

Deleting a basket deletes the payment attached to it: the relation is a `OneToOne` without cascade, and a
payment whose basket has gone is a row nothing points to any more.

---

## Reminders

A customer who validated an order and never paid it is reminded twice, the next day and a week later, and
never again - `BasketRetentionService` takes the order away at thirty days. Only a validated order can be:
a basket still `new` carries no e-mail more often than not.

The reminder links to the shared-payment route, which reopens the checkout of an order still waiting for its
money without asking for anything to be filled in again. Its token is only handed out when somebody shares
their order, so an abandoned basket is given one when its first reminder goes out.

An abandoned basket is not a concluded sale, so the exception article L34-5 of the CPCE makes for products
analogous to a previous purchase does not cover the reminder: it needs consent, which `Form\CoordinatesType`
asks for with the one box of the checkout that is not required, and which is read by the query itself rather
than by the caller. Nothing is sent while the shop is in test mode.

The count lives on the basket in `remindersSent` and never touches `modification`: the retention pass reads
that date to know when the visitor last touched their basket, and a reminder writing to it would push the
purge back every time it fires.

---

## Paying for somebody else

An order can be frozen and handed over instead of paid: it is numbered, its checkout is not opened, and what
comes back is a link to send to whoever is going to settle it — a gift list, a parent paying for a student, an
invoice a company settles.

Two different tokens keep the two sides apart. The customer keeps their own `securityToken` and sees everything;
whoever pays follows a `shareToken`, which opens a page showing what is being bought and what it costs, **and
nothing of who it is for**. Handing over the security token instead would hand the payer the delivery page —
the recipient's name and address, which is the one thing a gift must not disclose.

An order already settled, or taken back to a basket by its customer, says so and offers nothing to pay rather
than opening a second checkout. The payer lands on a page of their own once the provider is done with them, and
never on the customer's order history.

The link the customer is given is the short one, `/pay/{shareToken}` — the same address a payment link travels
by, and the one they forward in a text message as readily as in an e-mail. It is a 302 to `basket_shared_pay`,
and reads its token whatever its case: this is an address that gets dictated over the telephone and retyped.

---

## Payment links

Being paid for something the catalogue does not sell — a deposit, a repair, a job, an invoice settled online.
**Payment link**, on the orders index, asks for a label, an amount and the customer's address, and hands back the
address to send them.

It is the shared order above, with the shop typing the line in place of a catalogue: nothing of the checkout, the
webhook, the payment row or this back-office is written twice for it. The order is created `validated` with its
own `shareToken`, and whoever follows the link opens their own checkout exactly as a payer does.

```php
// The one entry point, wherever you would rather write the link yourself
$url = $basketService->createPaymentLink('Deposit, Rue des Lilas', 25000, 'marie@example.org');
```

The amount is typed VAT included, as every amount of a basket is held, and `payment-link-vat-rate` is what the
tax is taken out of it with — a shop charging none leaves it at `0`. The line counts as a service, so a settled
link never joins the orders left to ship.

**The e-mail is required.** It is where the payment confirmation goes, and the shop's own copy travels in it as
the blind copy every basket e-mail carries.

**The address handed back is the short one**, `/pay/{shareToken}` — 28 characters less than the long one, which
is what a text message is short of. The share token is unique in its own right, so the order number beside it in
the long address guards nothing; the short route is a 302 to `basket_shared_pay`, which stays the one page
holding the "already settled" case and the `noindex` headers. The payment stays on your own domain, and that
matters more than the characters: a customer asked for their card on a shortener's domain has every reason not
to click.

**A link is settled once and is meant to be settled soon.** It is one order, so paying it twice is not possible,
and an unpaid one is deleted after 30 days like any other abandoned order — the link then answers a 404. No
reminder is ever sent for one: the reminders read a consent that only the checkout form asks for.

Nothing of this kind can be added to a basket from the front. `PaymentLinkItemProvider::findItem()` resolves
nothing and `validateAddition()` refuses, which is what keeps a visitor from posting themselves a line worth what
they choose.

---

## Baskets and sessions

The current basket is named by the session, which PHP recycles after 24 minutes of inactivity by default: a
visitor who filled a basket and left the page open would come back to an empty one, the row staying in the
database until the purge above.

`EventSubscriber\BasketRecoverySubscriber` gets it back to them, on the way in, by two means tried in that order:

- the `basket_recovery` cookie, which is the only one an anonymous visitor has. It carries the basket's own
  random token, is `HttpOnly`, `SameSite=Lax`, and lives as long as an untouched basket does (14 days);
- the basket the customer's account carries, which follows them from one device to the next.

Only a basket still open is handed back, and never one belonging to somebody else — a cookie surviving a logout on
a shared computer opens nothing. On the way out, the cookie mirrors what the session holds: an open basket poses
it, an order, an emptied basket or a purged row takes it away. **Nothing to declare in your application**, and
nothing overridable is involved: the subscriber only ever re-seats the basket's id in the session, which
`BasketService` reads as it always has.

---

## Test mode

`payment-test-mode` is what tells a real order from a rehearsal, and it is switched from the dashboard's tile
rather than edited by hand. While it is on:

- the provider is called with its test keys (`stripe-secret-test`, `revolut-secret-test` and their webhook
  counterparts) instead of the live ones, so nobody is charged — Revolut's test keys being those of its sandbox,
  which is a separate space with its own orders
- every order number carries a `TEST-` prefix, and the basket pages carry a banner saying so
- the banner gives the test card of every offered provider, named after it (`4242 4242 4242 4242` for Stripe,
  `4929 4205 7359 5709` for Revolut), from the `label.test_mode_<slug>_card` keys of the `payment` domain — a
  provider shipping no such key is simply left out of the banner
- a payment links to the provider's test dashboard from the back-office, where the transaction actually exists

Nothing reads a key to guess any of this: a live key holding the word "test" anywhere used to turn a real shop
into a rehearsal, and a test key not holding it charged nobody while the site claimed it did.

Set both pairs of keys before switching, `isConfigured()` on the gateway answering whether the pair in use is
there at all. A shop whose active pair is missing - or unreadable, a sensitive value being decrypted on the way
out - says so on the dashboard (`PaymentAlertProvider`) and refuses to validate a basket rather than letting the
provider fail in front of the customer. [ShopBundle](https://github.com/975L/ShopBundle) has a test mode of its own, `shop-test-mode`, for a
catalog being filled in; the two are independent and either shows the shop's banner.

---

## Offering several providers

**A provider is offered to the customer as soon as its keys are filled in**, which is what `isConfigured()`
answers for the mode in use. A shop therefore opens a second provider by storing its keys and closes it by
clearing them — there is no list to keep in step with the keys, and so no way for the two to disagree.

`payment-gateway` still names one provider: **the one the basket pre-selects**, and the one an order nobody stood
in front of is charged with (a payment link, an order somebody else settles).

What follows from it:

- the basket shows a chooser only when more than one provider is offered; a shop paid through one asks nothing
- the slug the customer picks is checked against what is really offered before a checkout is opened — it comes off
  a form, and one naming a provider whose keys were cleared since the page was drawn would open a checkout the
  shop cannot be paid through. Anything unoffered falls back on `payment-gateway`, then on the first provider that
  does hold keys
- `Payment.gateway` records which provider took the money, and the customer's return is confirmed **with that
  provider** — not with the shop's default, which it may well not be
- the CSP names every offered provider's checkout, not only the default's
- `c975l:health-check:run` asks each of them whether its keys still authenticate, one row apiece
- the dashboard alerts when *no* provider holds keys, and when `payment-gateway` names one no bundle registers

A provider's display name in the chooser is the `label.gateway_<slug>` key of the `payment` translation domain.

---

## Adding a payment provider

Implement `c975L\PaymentBundle\Contract\PaymentGatewayInterface` in a service (autoconfigured, no manual tagging
needed) — see `c975L\PaymentBundle\Gateway\StripeGateway` and `c975L\PaymentBundle\Gateway\RevolutGateway` for
the two reference implementations, one built on a vendor SDK and the other on plain http calls. Five methods: the
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

## Inviting a guest buyer to open an account

A shop takes orders from visitors who never opened an account: those baskets carry the address typed at the
checkout and no account at all. Two things bring them together, and neither costs the buyer anything during the
checkout, which nothing is allowed to compete with.

**The invitation**, on the page that follows the payment and in the order confirmation, through the
`account_invitation` slot and the `Basket:AccountInvitation` component. It is shown only when the order belongs to
no account *and* the site offers a one-click sign-in (`oauth_login_providers()`, see ConfigBundle): asking somebody
to invent a password on the way out of a checkout is the friction this exists to avoid, so with no provider
configured nothing is displayed at all. The e-mail variant links back to the order's own page rather than carrying
the sign-in itself - a flow started from a mailbox is fragile, and antispam scanners follow the links they are sent.

Its wording says "create an account to find your orders again", never "this order belongs to no account": the
e-mail is sent when the payment goes through and read whenever, possibly days after its buyer opened one.

**The attachment**, on every login rather than on the sign-up: `BasketAccountLinkSubscriber` listens to
`LoginSuccessEvent` and hands the orders left under that address to the account that just signed in
(`BasketRepository::attachOrphansTo()`). One place rather than one per door, so it works for an account opened
through a provider, one opened at the registration form, and one that predates all of this. Running on every login
costs an indexed update matching nothing once the orders have been claimed, and needs no "already done" flag kept
anywhere.

Only ever for an account whose address has been proved - which is what `isEnabled` says, an account being enabled
by `EmailVerifier` once its owner followed the link sent to that address, or by an OAuth sign-in where the provider
vouched for it. Matching on an unproved address would hand a stranger's orders, delivery address included, to
whoever registered with their e-mail. Paid and shipped orders only: an abandoned basket is not a purchase, and
re-seating one would collide with the basket the visitor is filling right now.

The orders then show up in the customer area with no further code, `CustomerAreaController` already listing them by
account.

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

**Hand over the links your delivery already made, never mint one here.** The page is read again long after the
order, and a link minted on the visit would outlive what the buyer was promised — `expiresAt` is that promise,
and the page tells them that date. So a file is listed for as long as its emailed link works, and drops off the
page once it has expired; a permanent right to download calls for a fresh link the buyer asks for, not for
`getDownloads()` quietly making one.

Only baskets carrying a `user` are listed. Matching on the email address instead would hand someone the orders of
whoever used that address before them, the moment they register an account with it.

---

## Gating a media behind a purchase

**Not the same thing as the section above, however much they look alike.** A bought file is *downloaded* once the
order is delivered, and for as long as its emailed link lives. A gated media is *looked at in the page* because the
visitor paid for it - the paywall of a photo gallery, a video, a chapter. This bundle answers the one question that
needs answering, and nothing else:

```php
// In the bundle that owns the media - a gallery, a book, whatever sells access to itself
if (!$this->basketRepository->hasPaidFor($this->getUser(), 'gallery', $media->getId())) {
    throw $this->createNotFoundException();
}

return $this->privateFileResponseFactory->createInlineResponse($absolutePath);
```

`hasPaidFor(UserInterface|string $buyer, string $kind, int|string $itemId): bool` reads the buyer's paid and
shipped orders - by account for a `UserInterface`, by address for a string, exactly as `findPaidByUser()` and
`findPaidByEmail()` do - and says whether one of them holds that item of that kind. `$kind` is the `getKind()` of
whichever `BasketItemProviderInterface` sells it, `$itemId` the id it was added under. It says the purchase
happened; it says nothing about a delay, and it never expires on its own.

Three things stay outside this bundle, on purpose:

- **The paywall itself belongs to whoever owns the media.** It knows what a photo is, this bundle never will. Serve
  the file from outside `public/` with UiBundle's `PrivateFileResponseFactory::createInlineResponse()`, which sets
  an inline disposition, marks the response private and leaves `BinaryFileResponse` free to answer `Range`
  requests - without those, a video plays from its start and cannot be moved through.
- **Never write the real file path in the HTML**, `src` attributes included: always the controlled route, or the
  gate is one right-click away.
- **The teaser is a rendering matter** - a blurred thumbnail, a first few seconds, and the button putting the media
  in the basket. It belongs to the same bundle as the media.

A page gating a whole gallery asks once per media, so keep the answer for the request rather than calling this in a
loop over a hundred thumbnails.

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
