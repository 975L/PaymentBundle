# UPGRADE

## v5.x > v6.0

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
which `paymentDone` alone included, is removed with them; the invoice work tracked in `TODO-PaymentBundle.md` will
bring its own rendering rather than inherit a template nothing has exercised in years. If you overrode any of the
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
