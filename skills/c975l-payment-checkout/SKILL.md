---
name: c975l-payment-checkout
description: "Use this skill when working on the basket or the checkout of a c975L site — adding to and emptying the basket, validating it, sending the customer to the payment provider, confirming the payment, delivering the order, the customer area and the transactional emails. Covers the rule that decides when an order is real, the two paths that confirm a payment, and what must never be trusted. Triggers on: BasketService, Basket entity, Payment entity, basket_display, basket_validate, basket_paid, payment_webhook, PaymentWebhookController, applyNotification, confirmReturn, claimPaid, checkout_data, gateway_reference, payment-test-mode, customer_orders, BasketDownloadProviderInterface, ConfirmOrderMessage, ItemsShippedMessage, BasketCodeService, Discount, GiftCard, GiftCardService, GiftCardDesign, GiftCardController, gift_card_display, gift_card_reveal, gift_card_pdf, gift_card_recipient, GiftCardRecipientMessage, giftCardRecipientEmail, designImage, designText, scratch, basket_code_apply, VatCalculator, payment_vat, payment_vat_rate, lineRate, InvoiceService, InvoiceSequence, invoiceNumber, basket_invoice_pdf, shop-invoice-prefix, shop-invoice-mentions, setInvoiceIssuer, invoiceSeller, invoiceSellerAddress, invoiceSellerEmail, invoiceMentions, site-owner, site-address, site-contact-email, shippingLabels, findAwaitingShipping, payment_gift_cards, basket_shared, basket_shared_pay, basket_shared_paid, payShared, shareToken, recoveryToken, BasketRecoverySubscriber, BasketRetentionService, BasketReminderService, c975l:payment:baskets:retention, c975l:payment:baskets:remind, reminderOptOutAt, basket_reminder_unsubscribe, reminder_unsubscribe, payment-share-validity, payment-email-attachments, shop-email-bcc, PaymentAlertProvider, BasketIntegrityHealthCheckProvider, basket-integrity, findDeliveredWithoutFinishedPayment, findFinishedWithoutDeliveredBasket, findWithPaymentAmountMismatch."
---

# c975L PaymentBundle — the basket and the checkout

> The generic basket and checkout engine: what a visitor puts in a basket, how it is priced and validated, how a payment is confirmed, and when an order becomes real.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Service/BasketService.php`, `src/Entity/Basket.php`, `src/Entity/Payment.php`, `src/Controller/BasketController.php`, `src/Controller/PaymentWebhookController.php`, `src/Controller/CustomerAreaController.php`, `src/Repository/BasketRepository.php`, `src/Email/BasketEmailFactory.php`, `src/MessageHandler/`, `src/Service/BasketCodeService.php`, `src/Service/GiftCardService.php`, `src/Service/VatCalculator.php`, `src/Service/BasketRetentionService.php`, `src/Service/BasketReminderService.php`, `src/Management/BasketIntegrityHealthCheckProvider.php`, `src/Entity/Discount.php`, `src/Entity/GiftCard.php`, `src/Contract/GiftCardDesign.php`, `src/Controller/GiftCardController.php`, `templates/components/GiftCard/`, `src/EventSubscriber/BasketRecoverySubscriber.php`, `src/Command/`, `templates/basket/`, `templates/customer/`

**Related skills:** `c975l-payment-gateway` and `c975l-payment-items` in this same bundle; `c975l-config` and `c975l-management` in `c975l/core-bundle`.

## The one rule

**The site delivers on a payment the provider confirms, never on the url the customer returns with.**

`/shop/basket/paid/{number}/{securityToken}` is handed to the customer *before* they pay — they can open it, bookmark it or share it without ever having been charged. `BasketService::paid()` therefore refuses to deliver a basket that has something to pay and no `Payment` recorded as finished. Reaching that page proves nothing.

Delivering means: decrementing stock, issuing downloads, running every provider's `onBasketPaid()` and sending the confirmation email. It happens **once**, whichever path gets there first.

**A basket validated but never paid, with nothing in the logs, is a CSP symptom.** `validate()` answers a redirection
to the provider, and `form-action` is checked on the whole redirection chain of a form navigation: the order is created,
the checkout opened, and the browser blocks the customer on the way to it. `EventSubscriber\CheckoutCspSubscriber`
completes that directive with the active gateway's own hosts, so a site serving a CSP needs nothing in its yaml.

## The two confirmation paths

Both end up in the same `paid()`, and each covers the other's blind spot:

| Path | Entry point | Covers |
| --- | --- | --- |
| Webhook | `POST /payment/webhook/{gateway}` → `applyNotification()` | the customer who pays and never comes back — closed tab, mobile, 3-D Secure |
| Return | `/shop/basket/paid/...` → `confirmReturn()` | the site whose webhook endpoint is misconfigured |

The return path re-reads the checkout **from the provider** (`ReturnAwareGatewayInterface::readReturn()`, handed the `Payment::getGatewayReference()` of the basket); it never believes the url. A gateway that does not implement it simply waits on the webhook.

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

## Codes: one field, two things

The basket page has **one** code field, and `Service\BasketCodeService` is what tells a promotional `Discount` from a `GiftCard` — the customer holds a code, not a category, and asking them which of the two they have is asking them to know how the shop is built. `resolve()` reads it, `apply()` writes it onto the basket, `redeem()` spends it. A code typed with spaces or dashes is accepted.

**One code per basket, and no stacking.** A card and a promotion applied together is a rule nobody wrote.

`Discount` comes in two kinds — `KIND_PERCENTAGE` and `KIND_AMOUNT`; a `GiftCard` is a balance, spent down over as many orders as it takes, and `GiftCardService::issue()` mints one either from a purchase or by hand from the CRUD.

**A code is spent in `paid()` and nowhere else.** A basket abandoned at the payment page must burn neither a quota nor a balance, and `claimPaid()` is what makes it happen once. `redeem()` answering false means it ran out between the last check and the payment: the order is paid for, so nothing is undone — it is logged.

The free-shipping threshold is weighed against **what the basket holds**, not what is paid: a ten-euro code must not cost the customer the shipping they had earned.

## A gift card is an object, not a code

`GiftCard:Card` draws one on UiBundle's flip card in the `credit-card` ratio: the visual full-bleed on the recto under the site's logo, a line of text and the amount; the same visual mirrored and faded on the verso, with the code.

**Everything printed on a card is copied onto it at issuance** — `designImage`, `designText`, `scratch`, handed over as a `Contract\GiftCardDesign` by whichever bundle sold it (`ShopBundle`'s `ProductBasketItemProvider` reads it off the basket, never off the catalogue). A design withdrawn from sale must not blank a card somebody still holds. This bundle knows nothing of where a visual came from.

**`GiftCard::$shareToken` is the address, never the code.** A card is bought for somebody else, who has no account: `gift_card_display` (`/gift-card/{shareToken}`) is their page, `noindex` / `no-referrer` / `no-store`, and the confirmation email prints that url beside the code. 128 bits against the basket's own 64: that link opens an afternoon, this one opens money that stands for a year.

**With the panel on, the code is not in the markup.** `gift_card_reveal` serves it once the panel is rubbed off (`assets/js/gift-card.js`), because a link pasted into a chat is fetched by a robot that reads the markup and runs no script. A card switched off is refused that request and still shows its visual and its balance. Panel off, the code is printed as it stands — robots included, which is what the back-office help text says.

**`gift_card_pdf` is the same card as a file**, its two faces drawn on an A4 to cut out at the ID-1 size (`templates/gift_card/pdf.html.twig`, `PdfGeneratorInterface`). A file holds no panel to rub off, so the code is printed on it — and it is refused on a card switched off, exactly like `gift_card_reveal`.

**The card can be sent to its recipient directly.** On a basket carrying a card, the checkout asks for an optional
`Basket::$giftCardRecipientEmail` and `$giftCardRecipientMessage`. Filled in, a `gift_card_recipient` e-mail goes
out beside the buyer's confirmation, through `GiftCardRecipientMessage` and its own handler — dispatched apart so a
bounced recipient address never costs the buyer their own confirmation, and re-read there rather than trusted from
the dispatch. It carries the amount, the buyer's word and the card's address, and **no code**: that message travels
through a mailbox that is not the buyer's, which is the panel's reasoning one step earlier. Left blank, nothing
changes — the buyer forwards the links from their own confirmation. Slots `gift_cards_shared` and
`gift_card_message`; run `c975l:ui:email-templates:ensure` or that e-mail has no body.

## VAT

`Service\VatCalculator::breakdown()` reads the tax back from the rates the lines were added with. A line's `totalVat` is the tax **held in its price**, never a rate multiplied by a quantity — the two disagree as soon as a price is rounded or a discount applied. `payment_vat(basket)` renders the breakdown and `payment_vat_rate(itemData, kind)` the rate of **one** line — `VatCalculator::lineRate()`, which answers `null` on a line taxed at no rate, printed as a blank cell and never as `0 %`. `payment_gift_cards(basket)` lists the cards an order bought.

## Invoices and address labels

**A paid order is numbered, once.** `Service\InvoiceService::assign()` is called from `paid()` — the one path
`claimPaid()` has already made exactly-once — so the sequence follows settled orders and holds **no gap**. Format
`{prefix}{year}-{0000}`, prefix from `shop-invoice-prefix`. The counter is `payment_invoice_sequence`, one row per
year, **bumped by the database** (a DQL UPDATE, then a read inside the same transaction) rather than read,
incremented in PHP and written back — that read-modify-write is exactly how two orders settled in the same second
get the same number. Nothing there touches the unit of work, so drawing a number flushes nothing else.

**Who issued it is frozen with the number.** `assign()` copies the seller block (`site-owner`, `site-address`,
`site-contact-email`) and `shop-invoice-mentions` onto the order through `Basket::setInvoiceIssuer()` — the four
`invoice_seller*` / `invoice_mentions` columns — and `pdf()` reads them back from there, never from the
configuration: a shop renamed, moved or crossing the VAT threshold would otherwise reissue its old invoices under
whoever it has become since. Null falls back on the live config, for the orders billed before the freeze and for a
shop that has not said who it is yet (a blank config value freezes `null`, not `''`). Do not print the seller with
`legal_var()` anywhere on the invoice, and do not write those columns outside `assign()`.

The PDF is drawn on demand and stored nowhere — the order is the record. `shop-invoice-mentions` prints at its
foot: registration numbers, VAT number, or the exemption. Served by `basket_invoice_pdf`
(`/shop/basket/invoice/{number}/{securityToken}`), by an **Invoice** back-office action, and by
`Email\InvoiceAttachmentProvider` under the kind `payment:invoice` — that last one only while
`payment-email-attachments` is on (see Emails).

**B2C only.** A business invoice is Factur-X — PDF/A-3 with its XML inside, through an approved platform — and
nothing here is that.

**Address labels**: a global action on the orders index, `BasketRepository::findAwaitingShipping()`, ten to an A4
at 105 × 57 mm. The sheet is a **table**, and a cell is 45 mm of content plus its padding rather than 57 mm with
the padding inside: floats and `box-sizing` are where dompdf and WeasyPrint disagree, and either mistake prints
every label off the paper. `ShippingLabelsSheetTest` guards the arithmetic. Address labels, not carrier labels —
a tracking barcode comes from the carrier's own API.

## Paying for somebody else

`validate()` with `$forSharing` numbers the order and freezes it *without* opening a checkout, answering a link to hand to whoever is going to settle it. Three routes carry it, and the token tells them apart:

| Route | Token | Shows |
| --- | --- | --- |
| `basket_shared` | `securityToken` | the customer's own page — everything, plus the link to hand over |
| `basket_shared_pay` | `shareToken` | the payer's page — what is bought, and **nothing of who it is for** |
| `basket_shared_paid` | `shareToken` | where the payer lands, which is never the customer's order page |
| `basket_short_pay` | `shareToken` | `/pay/{shareToken}` — a **302** to `basket_shared_pay`, and what both `basket_shared` and `createPaymentLink()` hand out |

**The checkout asks for no GDPR consent.** What it processes, the contract needs; `CoordinatesType` carries the
terms-of-use and terms-of-sales boxes alone, and `Basket:Validation` prints `text.gdpr_information` from the
**`ui`** catalog — core-bundle's, not `site`'s: this bundle depends on core-bundle and on nothing else — linking
to the page `url-privacy-policy` names and skipped while that setting is empty. `payment-share-validity` (days,
7) is what the checkout says a shared order is held for: a promise, not a mechanism — nothing is reserved and
`BasketRetentionService` still takes it away at thirty days.

**`shareToken` is deliberately not `securityToken`.** Sharing the latter would hand the payer the delivery page — the recipient's name and address, which is the one thing a gift must not disclose. `GiftCard` carries a `shareToken` of its own, named the same thing for the same reason and belonging to another entity: do not confuse the two.

An order already settled, or taken back to a basket by its customer, says so and offers nothing to pay rather than opening a second checkout.

## Payment links

`BasketService::createPaymentLink($label, $amount, $email, $description = null)` writes the same frozen order for something the catalogue does not sell — a deposit, a repair, an invoice — and answers the short address to send (see below). Written from **Payment link** on the orders index (`BasketCrudController::paymentLink()`), and from nowhere else on the front.

- The line is built by `Provider\PaymentLinkItemProvider`, this bundle's own `BasketItemProviderInterface`. Its `toBasketData()` is the one place the shape is written — **never hand-build that array**.
- `findItem()` answers null and `validateAddition()` refuses: the front must never be able to post itself a line worth what it chooses.
- The amount is typed **VAT included**, like every basket amount; `payment-link-vat-rate` is the rate it is taken out of.
- The line carries `CONTENT_FLAG_SERVICE`, so a settled link never joins the orders left to ship.
- **The e-mail is required.** `sendEmails()` dispatches no confirmation for an order naming nobody, and `EmailService` would otherwise fall back on the site's own address.
- The address it answers is `basket_short_pay` (`/pay/{shareToken}`), the one route of the bundle whose token is read **whatever its case** — lower-cased before the query, never left to the collation. A **302** to `basket_shared_pay` — a text message has 160 characters and the long address spends half of them. The share token is unique on its own; the number beside it guards nothing. **Do not render the payer's page there**: "already settled", "no longer available" and the `noindex` headers stay written once.
- The order is deleted after `ABANDONED_DAYS` like any unpaid one, and no reminder is ever sent for it — `findToRemind()` leaves the `CONTENT_FLAG_SERVICE` lines out, a shopkeeper who wrote the order chasing their own client themselves.

## A basket that outlives its session

`EventSubscriber\BasketRecoverySubscriber` writes a `basket_recovery` cookie carrying `Basket::$recoveryToken`, so a visitor who loses their session is handed their basket back; a logged-in user is matched on their last open basket too. The cookie lives as long as `BasketRetentionService::UNVALIDATED_DAYS`, the purge and the cookie sharing one window.

`Basket::toArray()` carries **none** of the three tokens, and `BasketCrudController` exports none of them — the same way a hashed password is never exported. A dump carrying them hands over every customer's order page and every open basket.

## Retention and reminders

Two commands, scheduled by `Scheduler\PaymentMaintenanceTaskProvider` rather than listed by every site in its own `MaintenanceSchedule`:

| Command | Does |
| --- | --- |
| `c975l:payment:baskets:retention` | deletes unvalidated (14 d) and abandoned (30 d) baskets, archives delivered orders (2 y), drops what is past retention (10 y) |
| `c975l:payment:baskets:remind` | reminds a customer who validated and never paid, on the first and the seventh day |

The windows are constants on `BasketRetentionService` and `BasketReminderService` — read them, never re-type the number.

**A reminder asks for no consent and carries a way out.** It is the follow-up of an order the customer placed
themselves and left unpaid, not prospection: it goes out unasked, and every one carries the
`reminder_unsubscribe` slot linking to `basket_reminder_unsubscribe` — one click, no confirmation step, stamping
`Basket::$reminderOptOutAt`. `findToRemind()` reads that opposition itself, and leaves the payment links
(`CONTENT_FLAG_SERVICE`) out. Both links of the e-mail are built on the same share token, minted on the spot when
the order has none. **Do not add a consent box to the checkout for this**, and do not read the opposition from a
caller.

## Test mode

`payment-test-mode` is switched from the dashboard tile, never edited by hand. While on: the provider's test keys are used, the order number carries a `TEST-` prefix, the basket pages carry `Basket:TestMode`, and the payment CRUD stops linking to the provider's live dashboard. The prefix follows the setting, **not** the word "test" in the secret key.

## Emails

The four transactional emails (`confirm_order`, `download_information`, `items_shipped`, `counterparts_shipped`) go through UiBundle's `EmailService` with `wrapLayout: true` — their bodies carry no `extends`, the branded layout coming from `EmailLayoutRegistry`. `Email\BasketEmailFactory` builds the shared `EmailSendRequest`, its envelope read from the six `shop-email-*` keys, a blank one falling back on the site-wide `email-*` address — blank meaning trimmed, a key holding a space alone being one an `Address` would throw on.

**`shop-email-bcc` is the shop's only record of what went out**, and left empty it is silent — the email simply leaves without its blind copy. `Management\PaymentAlertProvider` therefore warns on the dashboard while it is empty, linking to the email group of the config listing.

**`payment-email-attachments` says whether an order email carries any file at all**, `false` by default and flipped from the dashboard tile beside the test-mode one. Which template carries which document — `payment:invoice`, and UiBundle's `legal:*` terms of sale — stays ticked template by template in the email builder; `BasketEmailFactory::attachments()` reads the switch before asking the renderer for any of them, so nothing is drawn while it is off. Do not read the switch anywhere else: an attachment provider answers what it is asked for, and the shop's decision is taken once, in the factory.

The handlers **throw** when a send fails, so Messenger retries rather than dropping the email silently.

## Customer area

`/account/orders` lists a logged-in buyer's paid orders, `/account/orders/{number}` shows one with its tracking, its lines and its downloads. **Only baskets carrying a `user` are listed** — matching on the email address would hand someone the orders of whoever used that address before them. An order that is not the asking user's own answers **404, not 403**: order numbers run in sequence.

Downloads come from `BasketDownloadProviderInterface`; with nothing implementing it the section is left out rather than drawn empty.

The same `Basket:Downloads` component is rendered on the paid page, gated on the basket being paid, so a buyer whose email is late or filtered still takes their files — the emailed link is never the only way to them.

## What is checked weekly

`BasketIntegrityHealthCheckProvider` (kind `basket-integrity`) runs six checks, one dashboard row each. They all
share the same shape: two rows a shop only ever reads apart, put side by side. Nothing else — no log line, no
error page, no email — ever reports any of them.

| Row | Reads |
|---|---|
| `#charged-not-delivered` | a finished `Payment` whose `Basket` never reached `paid`/`shipped` — the customer was charged and nothing followed |
| `#delivered-unpaid` | a delivered order with no finished payment, orders with `getPayable() === 0` excluded (they carry no payment row at all) |
| `#amount-mismatch` | `Payment::getAmount()` against `Basket::getPayable()`, the currency compared whatever its case |
| `#missing-number` | a delivered order without its invoice number |
| `#total-mismatch` | the sum of `Basket::$items` lines against `$total`/`$quantity`, an order whose lines carry no `total` key skipped rather than reported |
| `#unresolvable-items` | a payable basket whose lines no longer resolve through `BasketItemProviderRegistry` — `has()` before `get()`, a kind whose bundle is gone being one of the answers |

Rules that keep it read rather than switched off: twelve months back only, an hour's grace on a confirmed payment
(the webhook and the customer's return settle the order within seconds), `Basket::$testMode` orders left out of
all six by the queries themselves, and each check guarded on its own — `HealthCheckRunner` drops **every** row of
a provider that throws, and no rows at all reads as "nothing to report".

---

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
- **Do not stack a discount and a gift card** on one basket, nor spend a code before the payment is confirmed.
- **Do not compute VAT as a rate times a quantity** — read it back off the line.
- **Do not hand the `securityToken` to whoever is asked to pay**; `shareToken` is the payer's, and shows nothing of the address.
- **Do not add a `payment_link` line from anywhere but `createPaymentLink()`**, nor build its basket entry by hand instead of through the provider.
- **Do not export, serialize or log the three tokens.**
- **Do not weigh the free-shipping threshold against what is paid** rather than what the basket holds.
