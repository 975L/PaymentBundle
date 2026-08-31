# UPGRADE

## v6.5 > v6.6

**Nothing to do on a site**, the basket pages simply stop crashing where ShopBundle is not installed. Two contracts
move, and both are the business of a bundle plugging into this one.

**`BasketRecommendationProviderInterface` gains `getTemplate(): string`**, the template drawing what
`getRecommendations()` returns, handed the entries as a `recommendations` variable. An implementor must add it -
ShopBundle answers it from v2.6.0:

```php
public function getTemplate(): string
{
    return '@c975LShop/components/Product/Recommendations.html.twig';
}
```

**The "continue shopping" button is drawn from the new `CatalogueBasketItemProviderInterface`**, optional beside
`BasketItemProviderInterface`, whose `getCatalogueUrl(): ?string` returns the address of the listing to go back to.
Nothing implementing it is nothing to go back to, and the button is left out. Add it to the provider selling out of
a catalogue.

**The download links of the `download_information` e-mail carry a `url` key instead of a `token`**, built by
whichever bundle owns the download route, as `BasketDownloadProviderInterface` already did for the order page.
A bundle sending that e-mail passes `['title' => ..., 'url' => ..., 'size' => ...]` per link.

## v6.4 > v6.5

**Delivery is priced on a grid written in the back office, and `shop-shipping` is gone.** A zone groups the
countries posted at one tariff and carries its weight tiers; a parcel is charged at the first tier it fits in.
The weight comes from the selling bundle through the new `WeighableBasketItemProviderInterface` - ShopBundle
answers it from v2.5.0.

```sql
CREATE TABLE payment_shipping_zone (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, countries JSON NOT NULL, active TINYINT(1) NOT NULL, creation DATETIME NOT NULL, PRIMARY KEY(id));
CREATE TABLE payment_shipping_rate (id INT AUTO_INCREMENT NOT NULL, zone_id INT NOT NULL, max_weight INT DEFAULT NULL, price INT NOT NULL, INDEX IDX_shipping_rate_zone (zone_id), PRIMARY KEY(id));
ALTER TABLE payment_shipping_rate ADD CONSTRAINT FK_shipping_rate_zone FOREIGN KEY (zone_id) REFERENCES payment_shipping_zone (id) ON DELETE CASCADE;
```

Generate it with `doctrine:migrations:diff` and run it.

**A shop that writes no zone posts everything free**, which is deliberate - nothing written is nothing charged -
but it is silent, so do this before upgrading in production. **To keep exactly what you had**, write one zone
with **no country** (the default zone, where every country falls) and **one tier with no ceiling**, priced at
whatever `shop-shipping` held. That reproduces the old flat rate for every parcel, weighed or not. Note the
value down first: the key leaves `configs.json` with this release. The dashboard's health check reports an empty
grid, a missing default zone and a zone whose tiers all stop short.

**A free-shipping threshold left unset is now no threshold at all.** `shop-shipping-free` read as an amount made
`$total < null` false on every basket, so a shop that had never set one was charging no delivery whatever its
rate said. It now means "no free delivery", and delivery is charged. **A shop that relied on that silence starts
charging its customers for delivery the day it upgrades** - set `shop-shipping-free` if free delivery was the
intent.

**The checkout asks for the country in a list.** `CoordinatesType` stores the ISO 3166-1 alpha-2 code, a zone
naming "FR" recognising nothing in "france". Orders already placed keep the free text they carry: they are frozen
snapshots and are never priced again, so there is nothing to backfill.

New key: `shop-shipping-country`, the country the basket page estimates delivery on before the customer has given
an address. The final amount is computed at the checkout, once the address is bound - which is what `validate()`
now recounts the basket for.

## v6.3 > v6.4

**An invoice now states who issued it the day it was issued.** The seller block and the legal mentions are copied
onto the order when its number is drawn, instead of being read back off the configuration every time the file is
drawn again: a shop that is renamed, moves, or crosses the VAT threshold would otherwise be reissuing its old
invoices under whoever it has become since, which the six years an invoice must stay reproducible do not allow.
Four columns carry it:

```sql
ALTER TABLE payment_basket ADD invoice_seller LONGTEXT DEFAULT NULL;
ALTER TABLE payment_basket ADD invoice_seller_address LONGTEXT DEFAULT NULL;
ALTER TABLE payment_basket ADD invoice_seller_email VARCHAR(255) DEFAULT NULL;
ALTER TABLE payment_basket ADD invoice_mentions LONGTEXT DEFAULT NULL;
```

Generate it with `doctrine:migrations:diff` and run it. **Leave them null on the orders already billed**: that is
what makes those invoices go on reading the configuration as it stands, which is what they were already doing -
backfilling today's company onto them would state as frozen something that never was.

## v6.2 > v6.3

**The documents an order email carries are now behind one switch, off by default.** `payment-email-attachments`
says whether an order email travels with any file at all - its invoice (`payment:invoice`) and the terms of sale
(UiBundle's `legal:*`) - and a fresh install holds `false`. Which template carries which document is unchanged:
it stays ticked template by template in the email builder.

**A site whose templates already had one of them ticked stops attaching it the day it upgrades**, silently, its
emails going out with their body and nothing else. Flip the tile back on from the dashboard - *Activer l'envoi
des factures et CGV*, beside the test-mode one - and everything already ticked travels again. Nothing else is to
be done: no migration, no re-seeding, the ticks are where they were.

The tile is painted as a warning while the sending is off, which is the opposite of the test-mode tile beside it:
where the law asks for a durable medium, the file in the customer's mailbox is what answers, and a link to a page
that can be rewritten afterwards is not.

## v6.1 > v6.2

**The reminder of an unpaid order asks for no consent and carries a way out instead.** A reminder is the
follow-up of an order the customer placed themselves and left unpaid, not prospection, so it goes out without
being asked for; what article L34-5 of the CPCE then asks is that it can be stopped in one click, which the new
`reminder_unsubscribe` slot at the foot of both reminders does (`basket_reminder_unsubscribe`, no confirmation
step). `Basket::$reminderConsent` is replaced by `$reminderOptOutAt`, the day the customer asked to hear no more:

```sql
ALTER TABLE payment_basket ADD reminder_opt_out_at DATETIME DEFAULT NULL;
ALTER TABLE payment_basket DROP reminder_consent;
```

Generate it with `doctrine:migrations:diff` and run it. **Know what it changes for the orders already taken**:
every validated-and-unpaid basket whose customer had left the box unticked becomes remindable, the new column
being null for all of them. A shop that would rather not reopen that backlog carries the old answers over, with
this line added by hand to the generated migration **between** the two statements above - `reminder_consent` has
to still be there to be read:

```sql
UPDATE payment_basket SET reminder_opt_out_at = NOW() WHERE status = 'validated' AND reminder_consent = 0;
```

The customers who had ticked the box keep their reminders, everyone else is opted out - which is the behaviour
the shop had the day before the upgrade.

Payment links are left out of the reminders altogether now (`CONTENT_FLAG_SERVICE`): the order was written in
the back-office by a shopkeeper who chases their own client themselves.

**The two reminder templates gain a slot.** `c975l:ui:email-templates:ensure` backfills it into the templates a
site was already seeded with - run it once after the migration, or the reminders go out with no way out at their
foot.

**The checkout no longer asks for a GDPR consent.** What it processes, the contract itself needs, and a box the
customer could not refuse without giving up their order was never a consent. `CoordinatesType` drops its `gdpr`
field, and `Basket:Validation` prints `text.gdpr_information` instead - UiBundle's own key, linking to the page
the `url-privacy-policy` setting names. **Fill that setting** (Configuration → Legal): while it is empty the line
is skipped entirely and the checkout says nothing at all. A shop that overrode `Basket/Validation.html.twig` has
that block to bring into its copy. The checkout fields also lost their placeholders, their label saying what is
asked.

**Nothing to run for the rest**: the six `basket-integrity` checks appear on the Health check page by themselves,
weekly, and the four gateway keys carry the *info* severity instead of *danger* - a shop charging with Stripe has
no Revolut key to fill in, and `PaymentAlertProvider` is what says a shop takes orders it cannot charge.

## v6.0 > v6.1

**The customer picks who they pay through, and a provider is offered as soon as its keys are filled in.**
[Behaviour change] A shop that stored keys for a provider it no longer charges with **starts offering it again**
at the basket: clear those keys to close it. Nothing changes on a shop holding one pair of keys, which goes on
charging through that provider without ever showing a chooser. `payment-gateway` keeps its entry and changes
meaning: it names the provider the basket **pre-selects**, and the one an order nobody stands in front of is
charged with (a payment link, an order somebody else settles). `PaymentGatewayRegistry::getOffered()` is what
answers the list; `getActive()` still answers that default. Two consequences worth knowing: the dashboard no
longer alerts when the *default* holds no key while another provider does — the shop can charge, so it says
nothing — and `c975l:health-check:run` now reports one row per offered provider instead of one for the default.

**If you override a template of the basket**, `SecurePayments` and `TestMode` no longer test
`config('payment-gateway')`; they read the new `payment_gateways()` Twig function, which answers the offered
slugs. A copy of your own still testing the config value will hide its logo the day a second provider is stored.

**`c975l:payment:revolut:webhook` declares the Revolut webhook** for the account the test mode names, prints the
signing secret and stores it through `c975l:config:set`. Nothing else declares it — Revolut offers no screen for
a webhook, and answers its secret once. Run it once per account (the sandbox and the live space are two separate
accounts there): it reads what is already declared for the endpoint and refuses to stack a second webhook on it,
`--replace` taking the old one down first.

**A rehearsal is billed no invoice number.** `payment_basket` gains a `test_mode` column - run
`doctrine:migrations:diff` then `doctrine:migrations:migrate`, the default `0` being right for every order already
taken. An order is stamped with the mode the shop was charging in when its checkout was frozen, and
`InvoiceService::assign()` draws no number for one carrying `1`: the sequence an accountant reads holds no document
for a sale that never happened, and none of the gaps that deleting such an order afterwards would leave. The
**Invoice** action and the customer's own download hide themselves for it, as they already do for any order with no
number. Orders taken in test mode *before* this upgrade keep the numbers they drew - the column cannot say what the
shop was charging in months ago.

**A payment link charges for what the catalogue does not sell.** Nothing to install and no migration: the order
it writes is an ordinary `payment_basket` row, frozen like a shared one. **Payment link**, on the orders index,
asks for a label, an amount and the customer's address. Set `payment-link-vat-rate` if you charge VAT — it is the
rate taken out of what you type, prices being held VAT included here, and it stays at `0` for a shop that charges
none.

**A new public route, `/pay/{shareToken}`** — the payer's page at an address short enough to travel in a text
message, answering a 302 to `basket_shared_pay`, and what the page a customer shares their own order from now
hands out too. Its token is read whatever its case — the only route of the bundle that is. Nothing to declare:
the bundle's routes are loaded from its controllers. Check it collides with nothing your own application already
serves under `/pay/`.

**`BasketServiceInterface` gains `createPaymentLink()`** [BC-Break]. **If you have written your own
implementation of that interface**, add the method; the one shipped here writes the frozen order and returns the
payer's address. Nothing changes for the applications that autowire `BasketService` itself, which is every one of
them.

**The order confirmation is no longer dispatched for an order naming no e-mail address.** Every order taken from
the front carries one, `CoordinatesType` asking for it, so this changes nothing for a shop selling as it did. It
exists because `EmailService` falls back on the site's own address when a message names no recipient: a basket
written without one would have sent the customer's confirmation to the shop, or failed outright on a site having
no fallback address either and left Messenger retrying for ever.

**Revolut charges alongside Stripe.** Nothing changes on a shop already running: `payment-gateway` still holds
`stripe`, and the new provider is simply offered in the picker. To switch, store the Merchant API keys
(`revolut-secret`, `revolut-secret-test`) and declare the webhook — **Revolut has no screen for that**, it is one
API call per space, and the `signing_secret` it answers goes to `revolut-webhook-secret` /
`revolut-webhook-secret-test`. The README and the back-office procedure both write the steps out. Two habits from
Stripe do not carry over: an order links to nothing in the back-office, Revolut publishing no stable address for
one in its portal, and the customer who gives up on the checkout page is not sent back to their basket.

**`ReturnAwareGatewayInterface::readReturn()` takes a second argument** [BC-Break], the reference the provider
gave the checkout when it was opened — `readReturn(Request $request, ?string $reference)`. **If you have written
a gateway of your own**, add the parameter; you are free to ignore it, as Stripe very nearly does. It exists for
a provider that writes nothing of its own into the return url, which then has the reference kept on the payment
and nothing else to look the payment up by. `BasketService` hands over `Payment::getGatewayReference()`.

**Orders are invoiced.** `payment_basket` gains `invoice_number` (unique, nullable) and `invoice_date`, and a new
`payment_invoice_sequence` table holds the counter — generate the migration as usual. The orders already paid
carry no number and get none: numbering is done when an order is paid, and back-filling the old ones would build
a sequence out of order. Set `shop-invoice-prefix` (default `FA`) and, above all, `shop-invoice-mentions`: what
is printed at the foot of every invoice is your own registration and VAT situation, which no bundle can guess.

**A back-office action prints address labels.** Nothing to install: **Address labels** on the orders index draws
ten 105 × 57 mm labels to an A4. A PDF engine is needed, and there is always one — dompdf ships with
`c975l/core-bundle`.

**A gift card can be e-mailed to whoever it was bought for.** `payment_basket` gains
`gift_card_recipient_email` and `gift_card_recipient_message`, both nullable - generate the migration as usual.
Nothing changes on an existing shop until a buyer fills the two new checkout fields in, which only appear on a
basket carrying a card. Run `c975l:ui:email-templates:ensure` after the migration: it seeds the new
`gift_card_recipient` template, without which that e-mail has no body to send.

**The order e-mails are composed in the back office, and their Twig bodies are gone.** [BC-Break]
`templates/emails/confirm_order.html.twig`, `items_shipped.html.twig`, `counterparts_shipped.html.twig` and
`download_information.html.twig` are removed, along with the `Basket:ItemsReminder` component they shared. The
seven e-mails this bundle sends — those four, plus `gift_card_recipient` and the two reminders — are now
`EmailTemplate` rows an admin composes, declared by `Email\PaymentEmailTemplateProvider` and seeded by
`c975l:ui:email-templates:ensure`, which you should run once after upgrading. A site that never overrode anything
has nothing to do beyond that command: the rows are seeded with the same wording, read from this bundle's
`payment` catalogue in French, English and Spanish.

**If you overrode one of those four templates under `templates/bundles/c975LPaymentBundle/`**, your copy is now
dead markup: nothing loads it any more, and no error says so — the e-mail simply goes out saying what this bundle
declares. Delete it, then rewrite the same change on the `EmailTemplate` row, in the back office, under the
e-mail's own name. What you can rewrite there is every **sentence**, plus the order of the blocks; what you
cannot is the computed part — the order's lines, the delivery address, the download links, the gift cards. Those
appear as `slot` blocks that carry a name and no content, filled at send time by `Email\BasketEmailFactory`, and
a slot with nothing to show renders nothing rather than an empty table. **If your override changed one of those
fragments** rather than the prose around it, override the matching template of `templates/emails/slots/` instead:
those are still ordinary Twig files, and they are where that markup now lives.

**If you translated this bundle's e-mail wording** in your app's `payment` catalogue, keep it: the declaration
reads the catalogue, so the rows are seeded with your strings. An admin's own rewriting on the row outranks both
afterwards, and running the command again never overwrites it — on a row that already exists, the sentences are
left strictly alone. What it still does is **append a slot the bundle has since declared and this row has never
held**, at the end of the blocks, so an e-mail gaining a fragment in a later version shows it rather than
silently dropping it; move it where it belongs and it stays there. A slot you deliberately deleted is not put
back.

**A gift card is now an object with a face, and a page of its own.** `payment_gift_card` gains four columns —
`share_token` (unique), `design_image`, `design_text` and `scratch` — so run `php bin/console
doctrine:migrations:diff` then `doctrine:migrations:migrate`. Nothing else is required: the cards already issued
carry no address and no visual, are printed as they were, and go on being spent by their code alone. The new
`/gift-card/{shareToken}` page is what a buyer forwards to whoever the card is for, and its code is served by
`/gift-card/{shareToken}/code` rather than written into the markup.

**`GiftCardService::issue()` takes a fifth argument**, a `Contract\GiftCardDesign` saying what the card is
printed with. Optional and last, so a caller of your own goes on compiling; a card issued without one keeps the
scratch panel and carries no visual. **If you sell cards through a `BasketItemProviderInterface` of your own**,
hand that design over from what the basket copied rather than from your catalogue — a design withdrawn from sale
must not blank a card somebody still holds.

**The VAT is computed, and `totalVat` changed meaning.** [BC-Break] A basket line used to carry
`quantity × rate`, which is neither an amount nor a rate; it now carries the tax held in what the line is sold
for, in cents, taken out of a price held VAT included (`VatCalculator::included()`). **A provider of your own
writing that key must do the same** - the baskets already open answer the old value until their next change.
`VatCalculator` reads the whole basket from there: one entry per rate, the shipping shared between the rates in
proportion of what each one weighs, a promotional code lowering the base and a gift card leaving it untouched, a
card sold left out of it altogether. Nothing is stored, so an order answers the same amounts the day a rate is
changed in the back-office.

**The basket draws one table.** [BC-Break] The articles and the totals used to be two tables whose columns landed
in two different places; there is now a single `.basket-table` with a `<thead>`, one `<tbody>` per kind of item and
a `<tfoot>` carrying the subtotal, the shipping, the code, the total including VAT and the tax it holds. The
`kind="simple|complex"` prop of `Basket:Display`, `Basket:Items`, `Basket:Item` and `Basket:Total` is gone with the
detail it drew, which named no translation key and read a `vatAmount` no entity has. **If you have overridden one
of those templates**, take the new markup as your starting point: the stylesheet's `td` and `th` rules are now
scoped to that table, where they used to size every cell of the site.

**`Handlers.getCurrencySymbol()` is gone.** [BC-Break] `Handlers.formatAmount(cents, currency)` replaces it and
answers what the server's `|format_currency` renders, `Intl.NumberFormat` carrying the separator and the place of
the symbol - the amounts the basket rewrote after a click read `12.00 €` on a French page.

**A basket now survives the loss of its session, and that needs one new column.** `Basket` gained a
`recoveryToken`, posed when the basket is created and kept in a cookie of the visitor's browser: a basket filled
without an account used to be named by the session and by nothing else, so PHP recycling that session - after 24
minutes of inactivity, by default - left the visitor with an empty basket and the row in the database. Run
`php bin/console make:migration` then `doctrine:migrations:migrate` in your application. **The baskets already
open when you migrate carry no token** and stay as they are: they are recovered from the customer's account if
they have one, and the nightly purge takes the others away as before.

**The cookie is functional, not analytical**: it carries a random token naming one basket, is `HttpOnly`, and
lives exactly as long as an untouched basket does (14 days). Nothing to declare to your visitors beyond what a
session cookie already is.

**`BasketRepository::findUnvalidated()` now reads the last change instead of the creation.** A basket the visitor
came back to yesterday is a shopper still shopping, whatever day it was opened, and the nightly purge no longer
takes it away on the strength of its age alone.

**`Basket::toArray()` no longer carries `securityToken`, `shareToken` nor `recoveryToken`.** It answers the
basket page's JavaScript, which never used them - **if you have overridden a template or a controller reading one
of the three from that payload**, read it from the entity instead.

## v5.x > v6.0

**`PaymentGatewayInterface` gained `getCheckoutDomains()`.** [BC-Break] It answers the hosts the customer's browser
is sent to when paying, as a CSP source list writes them - `['checkout.stripe.com']` for the gateway shipped here.
Nothing to do in an application: `CheckoutCspSubscriber` reads it to complete the site's own `form-action`, which
is checked on the whole redirection chain of a form navigation and would otherwise block the customer between the
order and the payment page. A gateway of your own has one method to add, and may answer `[]` if it charges without
ever navigating away.

**Nothing is delivered on the strength of the url the customer comes back on.** `BasketService::paid()` used to
deliver any basket it found in the `validated` state - decrementing stock, issuing downloads, sending the
confirmation email - and it was reached from one place only: the `basket_paid` route, which is the `success_url`
handed to the customer *before* they pay. It now refuses to deliver a basket that has something to pay and no
payment recorded as finished. **A basket with nothing to pay (`total === 0`) is unaffected** - it has no payment to
confirm and still goes through on its own.

**The payment now reaches the site by two paths, which back each other up:**

- the provider's webhook, which `applyNotification()` answers by *delivering* the basket, where it used to only
  flag its `Payment` as finished. A customer who paid and closed the tab before the redirect - routine on mobile
  and after a 3-D Secure step - left the basket `validated` for good: no email, no stock movement, no download,
  while its payment row read as finished;
- the customer's own return, now confirmed **with the provider** rather than taken at face value. `success_url`
  carries Stripe's `{CHECKOUT_SESSION_ID}`, the session is re-read from Stripe on the way back and only its own
  `payment_status` delivers the order.

Either path alone is enough, so **a site whose Stripe dashboard sends no `checkout.session.completed` still
delivers its orders**, and a customer who never comes back still gets theirs. Both end up in the same `paid()`,
behind the same gate. Nothing to do in your application - but do check the webhook endpoint is declared, it is no
longer the only thing standing between a payment and its order.

**`checkout.session.completed` no longer delivers a session Stripe reports as unpaid.** A session settled
asynchronously - SEPA debit, bank transfer - completes while the money is still on its way, and the shop would
have shipped against funds that may never arrive. Which methods a checkout offers is a setting of your Stripe
dashboard that nothing in the code constrains, so this is asked on every payment. **If your shop uses a delayed
payment method, the order is now delivered when the funds settle** (Stripe sends a second event), not when the
customer leaves the checkout.

**`PaymentNotification` gained an `$amount`, and a notification that does not match is dropped.** A basket can
still be added to once it has been validated, while the checkout the customer is paying was priced before that.
The gateway reports what the provider actually charged and `applyNotification()` compares it with **what the
basket now holds**, logging and refusing on a mismatch. **If you implement `PaymentGatewayInterface` yourself**,
pass the charged amount as the fifth argument of `PaymentNotification` - a gateway that cannot report one leaves
it null and behaves exactly as before.

This refuses in both directions, so a customer who edits their basket mid-checkout and then pays the stale
checkout ends up charged with the basket left `validated` and undelivered. That is deliberate: delivering
contents nobody paid for is worse, and the dashboard's *payments started and never confirmed* tile
(`Management\PaymentStatusProvider`) surfaces the case for an admin to settle by hand.

**A notification naming an unknown basket is logged and dropped rather than raised.** `applyNotification()` no
longer throws a `RuntimeException` there: the 500 it produced had the provider replay the same notification for
days over a basket that was not coming back. The `@throws` is gone from `BasketServiceInterface`.

**`BasketServiceInterface` gained `confirmReturn(Basket $basket, Request $request)`**, which the `basket_paid`
route calls instead of `paid()`. If you decorate or implement that interface, add the method; if you call
`paid()` from your own code, it is still there and still delivers - it simply refuses to do so without a
confirmed payment.

**Two new seams, both optional to implement:**

- `Contract\ReturnAwareGatewayInterface`, implemented on top of `PaymentGatewayInterface` by a provider able to
  confirm a payment when the customer returns. Kept apart on purpose, exactly like `VerifiableGatewayInterface`:
  **a gateway that does not implement it stays valid** and is confirmed by its webhook alone;
- `Gateway\StripeSessionReader`, the step that decides whether Stripe reports the money as arrived. Both paths read
  a session through it, so they cannot drift into disagreeing about what counts as paid. `StripeGateway` takes it
  as a third constructor argument - autowired, nothing to declare, but **if you instantiate `StripeGateway` by
  hand, pass `new StripeSessionReader()`**.

Reading a session no longer fetches its `PaymentIntent`: the session already carries the transaction id and the
payment methods, so every payment is one Stripe round-trip lighter. What is stored in `transaction_id` and
`payment_method` is unchanged.

**An edited basket calls off the checkout it had already opened.** A checkout stays payable at the provider until
it is paid or times out (24 hours at Stripe), and the customer who edits their basket still has it open in a tab.
Refusing that payment afterwards is all the site could otherwise do, and by then they are charged and take
delivery of nothing. `Payment` gained a `gateway_reference` column holding what the provider calls the open
checkout, and editing the basket expires it. It is null on the rows already there, and null again as soon as a
payment is settled or its checkout called off - it holds an open checkout, never a history.

**Two columns are added in this release** - `payment.gateway_reference` here and `basket.checkout_data` below -
so one migration covers both:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The signatures that move with it - the first five internal to `validate()` unless you drive them yourself, the last two widened to the type the form factory has always returned, which only a caller explicitly type-hinting `Form` can notice:

| Before | After |
| --- | --- |
| `PaymentGatewayInterface::createCheckout(): string` | `: CheckoutSession` (`url` + `reference`) |
| `BasketServiceInterface::createCheckout(): string` | `: CheckoutSession` |
| `BasketServiceInterface::createPayment(): void` | `createPayment(?string $gatewayReference = null)` |
| `BasketItemProviderInterface::onBasketValidated(): void` | `: array` - what to hand over |
| `BasketItemProviderInterface::onBasketPaid($b, $items)` | `($b, $items, array $checkoutData)` |
| `PaymentFormFactoryInterface::create(): Form` | `: FormInterface` |
| `BasketServiceInterface::createForm(): Form` | `: FormInterface` |

**If you implement `PaymentGatewayInterface` yourself**, return `new CheckoutSession($url, $reference)` instead of
the bare url - `$reference` may be null from a provider that names its checkouts nothing. Implement
`Contract\ExpirableGatewayInterface` on top of it to have those checkouts called off; **a gateway that does not
stays valid** and behaves as before, its stale checkout still refused on its amount at delivery time.

**A basket edited after it was validated goes back to `new`.** `addItem()` and `deleteItem()` checked no status:
a visitor could keep filling a basket whose checkout had already been priced and opened at the provider. They now
reopen it, so `validate()` numbers it again with a new order number and a new security token - and the checkout
left open at the provider can deliver nothing, `paid()` only ever delivering a `validated` basket. **Re-editing a
basket after abandoning a checkout stays legitimate and keeps working**; it is the stale checkout that stops being
deliverable. An order (`paid`, `shipped`) still named by the session is left alone entirely, and the visitor
starts a new basket rather than adding to it.

**A provider hands its pre-payment data over instead of stashing it, and gets it back at delivery.** This is the
one change to make in a bundle plugging into the basket. `onBasketPaid()` now also runs from the webhook - a
request from the payment provider, carrying no session of that customer - so anything a provider put in the HTTP
session at `onBasketValidated()` was lost for every customer who did not come back to the site before the webhook
landed. The two hooks are now symmetric:

```php
// Was: $session->set('contributor', $data);
public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array
{
    return $data;   // kept on the basket by this bundle, handed back verbatim below
}

// Was: $data = $session->get('contributor');
public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void
{
    // $checkoutData is exactly what the call above returned, [] when it returned nothing
}
```

`Basket` gained a `checkout_data` column for it, keyed by item kind. It is dropped as soon as the basket is
delivered or the checkout called off - it carries the customer's own details across the payment and is no
record. **Both signatures change, so every provider must be updated**: return `[]` and the hook behaves exactly
as before. `c975l/shop-bundle` and `c975l/crowdfunding-bundle` are aligned on it in their own releases - upgrade
the three together.

A reopened basket is also validated a second time, so `onBasketValidated()` runs again on it. Re-validating was
always reachable - a customer coming back through `cancel_url` and submitting the form again did it - but it is
routine now; since what it returns overwrites what the previous one returned, there is nothing to make idempotent
unless the hook writes elsewhere too. `onBasketPaid()` is unaffected: `paid()` delivers a basket once and once
only.

`createPayment()` writes the basket's own payment row over rather than creating a second one, `Basket` holding
exactly one `Payment` - a checkout abandoned and started again used to orphan the first, leaving a row that never
finished. Nothing to do; a payment already recorded as finished is never re-priced, its basket no longer being
editable.

**`PaymentWebhookController` no longer returns the exception message.** A failure answers `Webhook failed` with
the same 500, so the provider still replays it; what went wrong stays in the log rather than going back over the
wire.

**The order page no longer thanks a customer whose payment is not confirmed.** With the provider unreachable on
the way back and its webhook a moment behind, `basket_paid` is now reachable on a basket still `validated`.
`<twig:c975LPayment:Basket:PaidInfos>` takes a `confirmed` prop - defaulting to `true`, so an existing override
keeps working - and shows *label.payment_confirming* instead of the thanks when it is false; the tracking and
delivery blocks are left out until the order is real. Two keys are added to the three `payment` catalogs:
`label.payment_confirming` and `label.payment_confirming_info`. **If you override `PaidInfos` or
`basket/display.html.twig`, carry the branch over**, or your page will thank people for orders they do not have.

**`c975l:payment:migrate-legacy-tables` is removed.** Renaming `shop_basket`/`shop_stripe_payment` to
`payment_basket`/`payment_payment` was a one-off for installations predating the extraction from ShopBundle, and a
rename of those tables belongs with the bundle they came from. If you still have the old names, rename them by
hand before updating:

```sql
RENAME TABLE `shop_basket` TO `payment_basket`;
RENAME TABLE `shop_stripe_payment` TO `payment_payment`;
```

**This bundle no longer speaks the `shop` translation domain.** Every label it renders is read from its own
`payment` catalog. The `shop` domain is shipped by ShopBundle, which requires this package and not the other way
round, so a site running Payment without Shop - CrowdfundingBundle does exactly that - showed raw keys such as
`label.total` across the whole checkout. **If you override one of this bundle's templates, or translated its keys
in your app under the `shop` domain, move those overrides to the `payment` domain.** The keys themselves are
unchanged; only the catalog they are looked up in is.

**Two keys are new rather than moved**: `label.info_basket` and `label.info_payment`, the intro paragraphs of the
Basket and Payment CRUD index pages, existed in no catalog at all and were rendering as their own key.


**`Service\EmailService` and `Service\EmailServiceInterface` are gone.** The three transactional emails they sent
now go through UiBundle's `EmailService`, which resolves the addresses, honours the `email-debug` preview and
reports a failure rather than throwing. Nothing in this bundle injects `MailerInterface` any more.

If you implemented or decorated `EmailServiceInterface`, or called it from your own code, replace the call with an
`EmailSendRequest` built by `Email\BasketEmailFactory`:

```php
use c975L\PaymentBundle\Email\BasketEmailFactory;
use c975L\UiBundle\Service\EmailService;

public function __construct(
    private readonly BasketEmailFactory $basketEmailFactory,
    private readonly EmailService $emailService,
) {
}

// Was: $this->emailService->confirmOrder($basket);
$request = $this->basketEmailFactory->create($basket, 'label.confirm_order', 'confirm_order');
$this->emailService->send($request);

// Was: $this->emailService->shippedItems($basket, 'product');
$request = $this->basketEmailFactory->create($basket, 'label.items_shipped', 'items_shipped');

// Was: $this->emailService->downloadInformation($basket, $downloadLinks);
$request = $this->basketEmailFactory->create($basket, 'label.download_information', 'download_information', [
    'downloadLinks' => $downloadLinks,
    'expirationDays' => 7,
]);
```

`create()` takes the basket, the subject's translation key (domain `shop`), the template's base name under
`templates/emails/` and, optionally, what that body needs on top of `basket`. It fills From/Reply-To/Bcc from the
six `shop-email-*` config keys and sets `wrapLayout: true`. `send()` returns `false` on failure and stashes the
reason in `getLastError()` - it does **not** throw, so a Messenger handler that relied on an exception to get its
message retried must now test the return value and throw itself, as this bundle's two handlers do.

**`downloadInformation()` was the one method another bundle called.** `ShopBundle`'s
`ProductItemDownloadMessageHandler` uses it; it is rebranched on the recipe above in ShopBundle's own release.
Upgrade `c975l/payment-bundle` and `c975l/shop-bundle` together.

**The four email templates carry no layout of their own.** `confirm_order`, `download_information`, `items_shipped`
and `counterparts_shipped` are now bodies only - no `{% extends %}`, no `{% block email_content %}` - because
`wrapLayout: true` renders them and wraps the result through `EmailLayoutRegistry`: SiteBundle's branded layout
when that bundle is installed, UiBundle's bare shell otherwise. `templates/emails/layout.html.twig`, added earlier
the same day as a stopgap, is removed. **If you override one of the four under
`templates/bundles/c975LPaymentBundle/`, strip its `extends` and its surrounding block, or your email will go out
wrapped twice.**

**`paymentDone.html.twig` and `errorValidation.html.twig` are gone.** Both extended
`@c975LEmail/emails/layout.html.twig` from `c975l/email-bundle`, a dependency this bundle no longer requires, and
both read `Payment` properties the v6.0 rewrite dropped (`description`, `action`, `vat`, `orderId`, `stripeEmail`,
`stripeFee`) plus a `payment_display` route this bundle no longer declares - they could not have rendered even
with a working layout. Nothing in `src/` dispatched either one. `templates/fragments/merchantData.html.twig`,
which `paymentDone` alone included, is removed with them; `InvoiceService::pdf()` draws the invoice itself rather
than inheriting a template nothing has exercised in years. If you overrode any of the
three under `templates/bundles/c975LPaymentBundle/`, delete your copy with it.

**`BasketItemProviderInterface` gains `validateCheckout()`, which every provider must implement.** It is asked at the
very top of `BasketService::validate()`, before the gateway is looked up and before anything is numbered, charged or
persisted, and returns a translated message - or null - exactly like `validateAddition()`. A non-null answer raises
`BasketNotOrderableException`, which `BasketController` turns into a flash message and a redirect back to the basket,
left untouched.

This is not `validateAddition()` again. That one is asked one click at a time and knows nothing of what the basket
already holds, so five clicks on an item with one left passed it five times; and nothing at all was asked between the
moment a basket was filled and the moment it was paid for - a basket sits for days, and what it holds can run out, be
withdrawn or be taken offline in between. The free path (`total === 0`) is behind the new check too, where it never
reached `onBasketValidated()` at all.

A provider with nothing to check implements it as `return null;` and behaves exactly as before:

```php
public function validateCheckout(Basket $basket, array $itemsOfThisKind): ?string
{
    return null;
}
```

**The per-item basket count is gone.** `<twig:c975LPayment:Item:Quantity>` drew a basket icon and the quantity the
basket held of one item, next to its add button. Only the basket page ever refreshed it, so everywhere else it stayed
on `0` whatever the visitor added, and the basket bar already carries the count for the whole document. Delete the tag
from your own templates - a template calling it now raises a `ComponentNotFoundException`.

**The basket handlers borrow their translation from UiBundle.** `assets/js/handlers.js` imports
`@c975l/ui-bundle/handlers.js` for `getLanguage()` and `translate()`, and the three `assets/js/translations.{en,fr,es}.js`
are one `assets/js/translations.js` keyed by locale. Run `php bin/console c975l:config:check-importmap`, which writes the
`@c975l/ui-bundle/handlers.js` entry the import needs - without it the module fails to resolve and the basket controller
never registers. If you overrode one of the three translation files, move your strings into the single one.

**The basket's Stimulus controller registers itself.** `assets/controllers.js` no longer exports a `register(app)`
function: it starts its own Stimulus app, like every other c975L bundle, and `Service\ScriptProvider` has the front
layout load it wherever `bundle_scripts()` is rendered. Drop the two lines from your `assets/bootstrap.js`:

```js
import { register as registerc975lPayment } from '@c975l/payment-bundle/controllers.js';
registerc975lPayment(app);
```

and let `php bin/console c975l:config:check-importmap` write the `importmap.php` entry, `'entrypoint' => true` being
what the old one lacked. Without this, no add button answers the click and the basket bar never shows.

**Eight templates no controller rendered are gone.** The four `templates/pages/`, `templates/forms/paymentFreeAmount.html.twig`
and the three `templates/fragments/payment{System,Button,Link}.html.twig` were the pre-EasyAdmin back-office: nothing
rendered them, and they called `payment_display`/`payment_charge`, routes this bundle no longer declares - rendered, they
raised a `RouteNotFoundException`. If you overrode one of them under `templates/bundles/c975LPaymentBundle/`, delete your
copy with it. `templates/fragments/merchantData.html.twig` stays, `emails/paymentDone` including it.

**The Stripe error alert is gone.** `StripeErrorMessage`, its handler, `EmailServiceInterface::stripeErrorMessage()` and
the `stripe_error`/`errorStripe` templates are removed: nothing dispatched that message, so no such e-mail had been sent
since the v6 rewrite. A failing provider call is logged by `PaymentWebhookController` instead. If you implement
`EmailServiceInterface` yourself, drop the method from your class.

**Stripe is now one payment provider among others, which needs a migration.** `Payment` no longer names Stripe in
its columns: `stripe_token` becomes `transaction_id`, `stripe_method` becomes `payment_method`, and a new
`gateway` column records which provider charged:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The `gateway` column is null on the rows already there, and a payment naming no provider falls back on the active
one for its back-office link.

If your own code reads a payment, rename the four accessors with it:

| Before | After |
| --- | --- |
| `$payment->getStripeToken()` | `$payment->getTransactionId()` |
| `$payment->setStripeToken(...)` | `$payment->setTransactionId(...)` |
| `$payment->getStripeMethod()` | `$payment->getPaymentMethod()` |
| `$payment->setStripeMethod(...)` | `$payment->setPaymentMethod(...)` |

**`BasketService`'s two Stripe methods are gone, replaced by provider-agnostic ones.**
`createStripeSession(): array` is now `createCheckout(): string`, returning the url to redirect to rather than a
session id and a url; `processStripePayment($session): void` is now
`applyNotification(PaymentNotification $notification): void`, the provider's own event shape being read by the
gateway before it ever reaches the basket. Both were only ever called from inside this bundle - nothing to do
unless you called them yourself.

**`StripeWebhookController` is now `PaymentWebhookController`, on a url per provider.** The endpoint is
`/payment/webhook/{gateway}`, e.g. `/payment/webhook/stripe`. The historical `/shop/stripe/webhook` still answers,
so **the endpoints declared in your Stripe dashboards keep working and there is nothing to change there** - point
new ones at the generic url.

**Three configuration entries were added, which have to be loaded.** `payment-test-mode` switches the site to the
provider's test keys, `stripe-secret-test` and `stripe-webhook-secret-test` hold them, and `payment-gateway` names
the provider that charges - `stripe`, the only one shipped so far:

```bash
php bin/console c975l:config:load-all
```

Fill the two test keys in from the back-office, then use the dashboard's "test payments" tile rather than editing
the entry by hand. Until now the site guessed it was in test mode from the word "test" appearing in
`stripe-secret`: a live key holding it anywhere turned a real shop into a rehearsal, and a test key not holding it
charged nobody while the site claimed it did. **Switch the tile on before putting a test key in `stripe-secret`,
or set the test key in `stripe-secret-test` and leave the live one where it is.**

**The bundle now requires PHP 8.4 and Symfony 8.** It used to declare `"php": ">=8.0"` and `"symfony/*": "*"`, an unbound constraint that let Composer resolve Symfony against whatever PHP the application ran on - so an application on PHP 8.2 silently got Symfony 7 with a bundle only ever tested against Symfony 8. The requirements now say what is actually built and tested: `"php": ">=8.4"` and `"symfony/*": "^8.0"`. If your application is still on Symfony 7, stay on the previous release until you migrate - `composer update` will simply refuse to move rather than break anything.

**Your `App\Entity\User` must now implement `c975L\ConfigBundle\Contract\UserInterface`**, `Basket::$user` and `Payment::$user` being typed against it instead of `App\Entity\User`. See `c975l/config-bundle`'s own UPGRADE for the one-line change and why nothing else moves - no migration, no configuration, the column and the join stay identical.

## v3.x > v4.x

Changed `localizeddate` to `format_datetime`

## v2.x > v3.x

`c975LEmailBundle` now use `Symfony\Component\Mailer\MailerInterface`and `Symfony\Component\Mime\Email` which are NOT compatible with Symfony 3.x.

## v1.x > v2.x

When upgrading from v1.x to v2.x you should(must) do the following if they apply to your case:

- The parameters entered in `config.yml` are not used anymore as they are managed by c975L/ConfigBundle, so you can delete them.
- As the parameters are not in `config.yml`, we can't access them via `$this[->container]->getParameter()`, especially if you were using `c975_l_payment.defaultCurrency`, so you have to replace `$this->getParameter('c975_l_payment.XXX')` by `$configService->getParameter('c975LPayment.XXX')`, where `$configService` is the injection of `c975L\ConfigBundle\Service\ConfigServiceInterface`, or your can use the shortcut `$paymentService->getParameter('c975LPayment.XXX')` where `$paymentService` is the injection of `c975L\PaymentBundle\Service\PaymentServiceInterface`.
- The following parameters are now managed by c975L/ConfigBundle, so you can delete them from `parameters.yml` and `parameters.yml.dist`, but before that, copy/paste them in the config.
  - stripe_secret_key_test
  - stripe_publishable_key_test
  - stripe_secret_key_live
  - stripe_publishable_key_live
- Before the first use of parameters, you **MUST** use the console command `php bin/console config:create` to create the config files with default data.
