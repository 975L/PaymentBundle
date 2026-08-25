# Changelog

## v6.3.1

A released version is what is asked for

- `c975l/core-bundle` is required from `^1.17.4` and no longer from `^1.18`, a version that was never released - the constraint v6.3.0 carries, so a site asking for it resolves nothing (25/08/2026)

## v6.3.0

The shop says whether its orders travel with their documents

- Added the `payment-email-attachments` config, `false` by default: whether an order email carries the files its template has ticked (25/08/2026)
- `BasketEmailFactory` reads it before asking for any attachment, so nothing is drawn while it is off (25/08/2026)
- Added the toggle tile flipping it from the dashboard, beside the test-mode one (25/08/2026)
- The tile is painted as a warning while the sending is off, an order confirmed without its invoice and its terms being the state worth an eye (25/08/2026)
- `PaymentShortcutController` flips both tiles through one `toggle()`, the route name being the CSRF token id and the flash key its own suffix (25/08/2026)
- Requires `c975l/core-bundle` `^1.18`, for the `HealthCheckSiteWideInterface` below (25/08/2026)
- `BasketIntegrityHealthCheckProvider` declares itself site-wide, its six rows having been listed among the pages (25/08/2026)
- Added the `payment-email-attachments` and `payment-basket-integrity` guided projects, at 7015 and 7070 (25/08/2026)
- Added the `label.payment_email_attachments*`, `description.payment_email_attachments` and `flash.payment_email_attachments_*` keys in the three locales (25/08/2026)
- The `c975l-payment-checkout` skill states the switch, and that the factory is the only place it is read (25/08/2026)
- Added `GalleryShowcaseProvider`, `payment_shipping` being the only block kind the showcase had no example of (25/08/2026)
- `Basket:Shipping` takes `shipping`, `free` and `currency` as optional props, each falling back to its own configuration (25/08/2026)
- Added `tests/Service/GalleryShowcaseProviderTest.php` and `tests/Templates/ShippingTest.php` (25/08/2026)

## v6.2.1

The basket answers a body it cannot read rather than crashing on it

- `BasketService::addItem()` reads and checks the body before creating anything (24/08/2026)
- A refused call no longer leaves the empty basket it had just created behind it (24/08/2026)
- A kind no provider answers for is refused rather than reaching the registry (24/08/2026)
- `deleteItem()` and `applyCode()` read their body through the same `readPayload()` (24/08/2026)
- The three basket routes answer 400 on an unreadable body, where they answered 500 (24/08/2026)
- `BasketServiceInterface` states the `BadRequestHttpException` the three methods now throw (24/08/2026)
- Added `tests/Service/BasketMalformedRequestTest.php`, pinning the four refusals and the empty code that is not one (24/08/2026)

## v6.2.0

The orders check out against their own payments, weekly

- Requires `c975l/core-bundle` `^1.16`, which the checkout line and the datagrid wrapper read from (24/08/2026)
- Added `BasketIntegrityHealthCheckProvider`, six weekly checks under the `basket-integrity` kind (24/08/2026)
- They report a charge whose order was never delivered, an order delivered with no payment, an amount or currency off `Basket::getPayable()`, a delivered order without its invoice number, lines that do not add up, and a payable basket holding an article the catalogue no longer has (24/08/2026)
- Added `BasketIntegrityHealthCheckAdviceProvider`, listing the orders behind each count, one link apiece (24/08/2026)
- Each check is guarded on its own, `HealthCheckRunner` dropping every row of a provider that throws (24/08/2026)
- The six checks leave the test orders out, the queries themselves filtering on `Basket::$testMode` (24/08/2026)
- Orders are read twelve months back, and a payment confirmed within the hour is left alone (24/08/2026)
- Added `BasketRepository::findDeliveredWithoutFinishedPayment()`, `findWithPaymentAmountMismatch()`, `findDeliveredWithoutNumber()`, `findOrdersSince()` and `findPayable()` (24/08/2026)
- Added `PaymentRepository::findFinishedWithoutDeliveredBasket()` (24/08/2026)
- Added the `label.health_check_basket_*` and `label.health_check_advice_basket_*` keys to the `payment` catalogs (24/08/2026)
- The four gateway keys carry the *info* severity instead of *danger* (24/08/2026)
- `Payment::getId()` returns `?int`, like its own column and like every other entity's (24/08/2026)
- The reminder of an unpaid order is sent without being asked for, being the follow-up of an order the customer placed themselves (24/08/2026) [BC-Break]
- Removed the `reminderConsent` box from `CoordinatesType` and `label.reminder_consent` from the `payment` catalogs (24/08/2026) [BC-Break]
- Replaced `Basket::$reminderConsent` with `$reminderOptOutAt` (24/08/2026) [BC-Break] **Needs db migration** see [UPGRADE.md](UPGRADE.md)
- Added the `basket_reminder_unsubscribe` route, one click and no confirmation step (24/08/2026)
- Added the `basket/reminder_unsubscribed.html.twig` page it renders (24/08/2026)
- Both reminders carry the new `reminder_unsubscribe` slot, backfilled into the sites seeded before it (24/08/2026)
- The link is built on the same share token as the reminder's own payment link, minted once for the two (24/08/2026)
- `findToRemind()` leaves the payment links out (24/08/2026)
- Added `label.basket_reminder_unsubscribe`, `label.basket_reminder_unsubscribed` and `text.basket_reminder_unsubscribed` to the `payment` catalogs (24/08/2026)
- The checkout no longer asks for a GDPR consent, what it processes being what the contract needs (24/08/2026) [BC-Break]
- `Basket:Validation` prints `text.gdpr_information` instead, read from UiBundle's `ui` catalog and linking to the page `url-privacy-policy` names (24/08/2026)
- The checkout fields no longer carry a `placeholder`, their label saying what is asked (`CoordinatesType`) (24/08/2026)
- Dropped `placeholder.gift_card_recipient_message` from the `payment` catalogs (24/08/2026)
- The checkout says how long a shared order stays payable, read from the new `payment-share-validity` setting (24/08/2026)
- The Discounts and Gift cards indexes draw their row actions as icons alone (24/08/2026)
- Both wrap their datagrid in `.table-responsive`, which holds with UiBundle's `management/_datagrid.scss` (24/08/2026)
- A gift card can be deleted again, the balance going with it where the *active* switch only takes it out of circulation (24/08/2026)
- `label.test_mode` becomes `label.test` and `label.code` becomes `label.gift_card_code` on the two screens (24/08/2026)
- The README gains a *What the orders are checked for* section, and states the reminder's way out, `payment-share-validity` and the checkout's information line (24/08/2026)
- The `c975l-payment-checkout` skill states the same three, plus the six checks and their rules (24/08/2026)
- UPGRADE.md walks a shop through the column, the backlog of baskets it reopens, the slot to backfill and the setting to fill in (24/08/2026)
- Added `BasketIntegrityHealthCheckProviderTest`, `BasketIntegrityHealthCheckAdviceProviderTest` and `PaymentRepositoryTest` (24/08/2026)
- Added `CheckoutGdprInformationTest`, locking the information line, its catalog and its guard (24/08/2026)
- Extended `BasketControllerTest`, `BasketRepositoryTest`, `BasketReminderServiceTest`, `PaymentEmailTemplateProviderTest` and `CoordinatesTypeTest` (24/08/2026)

## v6.1.1

Guided projects moved into the 7000 block

- The six guided projects run at 7010 to 7060, the block `GuidedProjectProviderInterface` reserves this bundle (23/08/2026)
- The provider and its test read that range off the interface docblock rather than recopying every bundle's own (23/08/2026)

## v6.1.0

Revolut, discount codes, gift cards and shared payment

- Added `Gateway\RevolutGateway`, a second provider behind the same contract - its Merchant API called over the http client, and nothing of its shapes leaving the class (23/08/2026)
- Added `Gateway\RevolutOrderReader`, the step deciding whether a Revolut order says paid, read the same way by the signed webhook and by the customer's return (23/08/2026)
- Added the `revolut-secret`, `revolut-secret-test`, `revolut-webhook-secret` and `revolut-webhook-secret-test` settings, the sandbox and the live account being two accounts at Revolut (23/08/2026)
- Added `c975l:payment:revolut:webhook`: Revolut offers no screen to declare one and hands its signing secret over once, `--replace` to declare it again (23/08/2026)
- `composer.json` requires `symfony/http-client`, which is what Revolut is called over (23/08/2026)
- Added `PaymentGatewayRegistry::getOffered()` and the `payment_gateways()` Twig function, a provider being offered as soon as its keys are stored (23/08/2026)
- `payment-gateway` names only the provider the basket pre-selects, and no longer the one the shop charges with (23/08/2026) [BC-Break]
- Added `Basket:GatewayChoice`, the radio the customer picks a provider with, which says nothing on a shop offering one (23/08/2026)
- `validate()` reads the provider asked for and refuses one holding no key, the payment row recording which one charged (23/08/2026)
- `readReturn()` takes the reference kept on the payment, a provider writing nothing of its own into the return url having that and nothing else to look it up by (23/08/2026) [BC-Break]
- `PaymentAlertProvider` speaks up when no provider at all holds a key, the default alone holding none no longer reading as a shop that cannot sell (23/08/2026)
- `GatewayHealthCheckProvider` checks every offered provider rather than the default one (23/08/2026)
- `Basket:TestMode` names the test card of each offered provider, and no longer Stripe's alone (23/08/2026)
- Added `label.choose_gateway`, `label.gateway_stripe`, `label.gateway_revolut` and `label.test_mode_revolut_card` to the `payment` catalogs (23/08/2026)
- Added `Management\ProcedureProvider` and `config/procedures.json`, the Revolut setup written out as a back-office procedure in the three locales (23/08/2026)
- Added `tests/Gateway/RevolutGatewayTest.php`, `tests/Gateway/RevolutOrderReaderTest.php`, `tests/Command/RevolutWebhookCommandTest.php`, `tests/Management/ProcedureProviderTest.php` and `tests/Twig/GatewayExtensionTest.php` (23/08/2026)
- The README gained a `Configure Revolut` and an `Offering several providers` section (23/08/2026)
- `MenuProvider` adds the **Discounts** and **Gift cards** entries, the four reading their description from a `label.info_*` key (23/08/2026)
- Added four guided projects at orders 220 to 250: the payment link, issuing a gift card, a discount code and the shipping costs (23/08/2026)
- Added `Basket::$testMode`, stamped at validation - an order placed with test keys is never invoiced, a rehearsal leaving no gap in the sequence (23/08/2026)
- Added the `payment-gift-card-validity` setting, how long a card issued today stands (23/08/2026)
- Added the new settings' labels and help texts to the `site_config` catalogs (23/08/2026)
- The README documents the card as a file, the `gift_card_pdf` and `basket_invoice_pdf` routes, and `payment_vat_rate()` (23/08/2026)
- `c975l-payment-checkout` covers the printed card and the per-line rate, `c975l-payment-gateway` the checkout domains and their CSP (23/08/2026)
- Added `tests/Repository/DiscountRepositoryTest.php` and `tests/Repository/GiftCardRepositoryTest.php`, the switch, the dates and the quota read back off the claiming statements themselves (23/08/2026)
- Added a `config/whatsnew.json` entry for Revolut and one for the printed card, the day's five blocks folded into one (23/08/2026)
- `UPGRADE.md` names its pending section `v6.0 > v6.1`, the form every other section of the file takes (23/08/2026)
- `UPGRADE.md` states what the move to admin-composed e-mails costs an app that overrode one of the four Twig bodies, which the ChangeLog marked a BC-break without ever writing the recipe (23/08/2026)
- Added `VatCalculator::lineRate()` and the `payment_vat_rate()` Twig function, the one place a line's own rate is read - a gift card and a line taxed at no rate answering null rather than zero (23/08/2026)
- The rate an article is taxed at is stated on its line, on the basket page, in the order emails and on the invoice, which carries a column of its own for it (23/08/2026)
- A line taxed at no rate is left blank rather than printed as "0 %", what a shop charges no VAT on being its own mentions to explain (23/08/2026)
- Added the `label.vat` and `label.vat_rate` keys in the three locales (23/08/2026)
- Added `tests/Templates/VatPerLineTest.php` (23/08/2026)
- An order covered in full by a code or a gift card hands its providers the checkout data too, `onBasketValidated()` no longer being skipped by the free path (23/08/2026)
- `confirmReturn()` only drops the session's basket when the session names that very order, a payer settling a shared one keeping their own (23/08/2026)
- The discount code is printed on the basket its holder edits and nowhere else, a shared order's pages no longer handing a gift card's code to whoever settles it (23/08/2026)
- `validate()` reads the code again before freezing the order, and refuses one that stopped applying since the basket was last touched (23/08/2026)
- Added the `error.code_changed` key in the three locales (23/08/2026)
- `DiscountRepository::claimUse()` and `GiftCardRepository::claimAmount()` test the switch and the validity dates as well as the quota and the balance, so a code turned off or expired is no longer spent (23/08/2026)
- `BasketRetentionService` logs every abandoned order before removing it - its number, its figures and its gateway references, no personal data - "taken and never confirmed" being indistinguishable from "never paid" (23/08/2026)
- `VatCalculator::spread()` no longer divides by zero on a basket whose taxed lines are all sold for nothing (23/08/2026)
- Added `BasketAccountLinkSubscriber` and `BasketRepository::attachOrphansTo()`: the orders placed as a guest become the customer's the day that address signs in, whichever door they came through (23/08/2026)
- Only ever for an account whose address was proved (`isEnabled`), and only on paid or shipped orders (23/08/2026)
- Added the `account_invitation` slot and the `Basket:AccountInvitation` component: an order left by a guest invites its buyer to open an account, on its own page and in its confirmation email (23/08/2026)
- Offered once the payment went through and never during the checkout, and only on a site where a provider offers a one-click sign-in (23/08/2026)
- The email variant links back to the order's page rather than carrying the sign-in itself, which a mailbox starts badly and antispam scanners follow (23/08/2026)
- Added `BasketService::createPaymentLink()` and its `BasketServiceInterface` declaration, an order written for something the catalogue does not sell and handed over as a link (23/08/2026) [BC-Break]
- Added `Provider\PaymentLinkItemProvider` and `Contract\PaymentLinkItem`, the one item this bundle sells for itself - a line whose label and price are typed instead of picked (23/08/2026)
- The link's order is frozen exactly as a shared one is, so the checkout, the webhook, the payment row and the back-office read it as the order it is (23/08/2026)
- `findItem()` answers null and `validateAddition()` refuses: a payment link is minted in the back-office and can never be added from a page (23/08/2026)
- Added the `paymentLink` action to `BasketCrudController` and `templates/management/payment_link.html.twig`, the form writing one and the address it hands back (23/08/2026)
- Added the `basket_short_pay` route, `/pay/{shareToken}` - the payer's page at an address 28 characters shorter, which is what a text message is short of (23/08/2026)
- `createPaymentLink()` hands back that short address, the share token being unique in its own right and the number beside it guarding nothing (23/08/2026)
- The page a customer shares their own order from carries that same short address, which they send by text message as readily as by e-mail (23/08/2026)
- `/pay/` reads its token whatever its case, lower-cased before the query rather than left to the site's collation: this address is dictated and retyped (23/08/2026)
- Added the `payment-link-vat-rate` setting, the rate taken out of what the shop typed - prices being held VAT included everywhere here (23/08/2026)
- A link's line is counted as a service, so a settled one never joins the orders left to ship (23/08/2026)
- The order confirmation is only dispatched to an order that names somebody: `EmailService` falling back on the site's own address would send the customer's confirmation to the shop (23/08/2026)
- A checkout line whose item hangs under no catalogue entry is named by its own label, and no longer by an empty pair of brackets (23/08/2026)
- `Basket:Item` draws a line naming no parent without a link and without a picture, instead of asking for a route that was never declared (23/08/2026)
- The invoice names a line hanging under no catalogue entry by its own label, an accounting document no longer printing an empty pair of brackets (23/08/2026)
- Added `tests/Provider/PaymentLinkItemProviderTest.php`, `tests/Service/PaymentLinkTest.php` and `tests/Assets/PaymentLinkMarkupTest.php`, and extended `BasketControllerTest` with the short address (23/08/2026)
- `BasketRepositoryTest` reads the two clauses keeping an order nobody consented for out of the reminders, a guarantee that was until now an accident of defaults (23/08/2026)
- The README gained a `Payment links` section, and `UPGRADE.md` states the new interface method and the new setting (23/08/2026)
- Added a `config/whatsnew.json` entry for the payment links (23/08/2026)
- Added `BasketRepository::hasPaidFor()`, the one question a paywall asks: has this buyer paid for this item, in whatever order and however long ago - by account or by address, as the two finders it reads (23/08/2026)
- Added `Basket::holdsItem()`, which answers it for one order: the items being stored as `items[kind][id]`, no search written in SQL and no database dialect to please (23/08/2026)
- Added `BasketPaidForTest`, covering the kind, the id typed on either side and a buyer with no order at all (23/08/2026)
- The README and the `c975l-payment-items` skill document it as what it is - showing a paid media in the page, never to be confused with downloading a bought file (23/08/2026)
- The README no longer promises a bought file is downloadable from the customer area however long ago its emailed link expired: the page lists the links the delivery made, for as long as they last (23/08/2026)
- Added `BasketRepository::findPaidByEmail()`, which is how an order is found for a visitor who never opened an account - by address and in lower case on both sides (23/08/2026)
- The six basket emails are composed from admin-editable `EmailTemplate` rows, and from nothing else: the Twig bodies beside them are gone (23/08/2026) [BC-Break]
- Added `PaymentEmailTemplateProvider`, declaring the six emails in French, English and Spanish (23/08/2026)
- Every declared sentence is read from `translations/payment.*.xlf`, so the catalogue is the one place this bundle's default wording lives (23/08/2026)
- `label.download_instructions` carries the whole sentence and a `%days%` parameter, instead of being glued to `label.days` at render time (23/08/2026)
- Removed the `Basket:ItemsReminder` component and the six `templates/emails/*.html.twig` bodies, which duplicated the wording the templates now hold (23/08/2026) [BC-Break]
- `BasketEmailFactory` refuses an email with no body rather than sending a blank one (23/08/2026)
- A paid order is invoiced: `Entity\InvoiceSequence`, `Service\InvoiceService` and a number drawn once in `paid()`, so the sequence holds no gap and no repeat (23/08/2026)
- Added the `basket_invoice_pdf` route, an **Invoice** back-office action and a link on the customer's own order page (23/08/2026)
- Added `Email\InvoiceAttachmentProvider`, so a shop ticks its invoice onto whichever emails it wants it on (23/08/2026)
- Added the `shop-invoice-prefix` and `shop-invoice-mentions` configs, what an invoice is numbered and what it states at its foot (23/08/2026)
- Added the **Address labels** action: the orders still to post, ten to an A4 at 105 x 57 mm (23/08/2026)
- Added `BasketRepository::findAwaitingShipping()` (23/08/2026)
- An invoice carries a date of its own: reading the order's last change would redate a document the customer already holds (23/08/2026)
- Added `InvoiceServiceTest`, `InvoiceAttachmentProviderTest` and `ShippingLabelsSheetTest` (23/08/2026)
- A gift card can be sent straight to whoever it was bought for: two optional checkout fields, and a `gift_card_recipient` email beside the buyer's confirmation (23/08/2026)
- That email carries the amount, the buyer's word and the card's address, and no code - it travels through a mailbox that is not the buyer's (23/08/2026)
- Added `GiftCardRecipientMessage` and its handler, dispatched apart so a bounced recipient address never costs the buyer their confirmation (23/08/2026)
- `BasketEmailFactory::create()` and `BasketEmailSender::send()` take an address other than the buyer's (23/08/2026)
- Added the `gift_cards_shared` and `gift_card_message` slots, and the `Basket:GiftCardsShared` component (23/08/2026)
- Every basket email carries the documents its `EmailTemplate` row was ticked for, drawn through UiBundle's `EmailAttachmentRegistry` (23/08/2026)
- `BasketEmailFactory` hands the order and the language it was placed in to whoever draws them, so a shop attaches its terms of sale by ticking one box (23/08/2026)
- The order's lines can no longer be taken out of a composed order email, only moved (23/08/2026)
- Added `BasketEmailSender`, the one place an email is written in the language the order was placed in (23/08/2026)
- Added `Basket::$locale`, stamped at validation - the reminder and the shipping notice are sent from where the customer's request no longer is (23/08/2026)
- Split `basket_reminder` into `basket_reminder_first` and `basket_reminder_second`, an email block carrying no conditional (23/08/2026)
- Added the eight slot templates the composed emails are filled from (23/08/2026)
- `download_information` reads `expiration_days`, not `expirationDays` (23/08/2026) [BC-Break]
- The shared payment page says the customer's own words when reached from a reminder (23/08/2026)
- Added `label.reminder_pay_explanation` to the `payment` catalogs (23/08/2026)
- Added `tests/Email/BasketEmailSenderTest.php` and `tests/Email/PaymentEmailTemplateProviderTest.php` (23/08/2026)
- Added `Entity\Discount` and `Entity\GiftCard`, a promotional code and a balance the basket's one code field both take (23/08/2026)
- Added `Service\BasketCodeService`, telling a promotional code from a gift card and allowing one per basket (23/08/2026)
- Added `Service\GiftCardService`, minting a card from a purchase or by hand (23/08/2026)
- Added `DiscountCrudController`, `GiftCardCrudController` and the page issuing a card by hand (23/08/2026)
- Added `DiscountRepository` and `GiftCardRepository` (23/08/2026)
- Added `Basket:GiftCards` and the `payment_gift_cards()` Twig function, the cards an order bought (23/08/2026)
- A code is spent when the payment is confirmed and nowhere else (23/08/2026)
- Added `BasketService::payShared()` and `Basket::$shareToken`, an order frozen and handed over for somebody else to settle (23/08/2026)
- Added the `basket_shared`, `basket_shared_pay` and `basket_shared_paid` routes and their three templates (23/08/2026)
- The payer's page shows what is bought and nothing of who it is for, the share token deliberately not the security token (23/08/2026)
- `composer.json` requires `c975l/core-bundle` `^1.14`, the version naming `ShortcutProviderInterface::CATEGORY_TOGGLE` (23/08/2026) [BC-Break]
- `SkillsTest` reads a typed class constant, `const int UNVALIDATED_DAYS` no longer reading as absent (23/08/2026)
- Added `tests/Twig/VatExtensionTest.php`, `tests/Twig/GiftCardExtensionTest.php` and `tests/Form/CoordinatesTypeTest.php` (23/08/2026)
- The README gained a `Codes and gift cards` and a `Paying for somebody else` section, and the five routes they add (23/08/2026)
- `c975l-payment-checkout` covers the codes, the VAT, the shared payment, the recovery and the retention (23/08/2026)
- `c975l-payment-items` states that a provider hands over the links its delivery made, with their `expiresAt` (23/08/2026)
- Added a `config/whatsnew.json` entry for the codes and the gift cards (23/08/2026)
- Added `BasketRetentionService`, which holds how long every kind of basket lives and the nightly pass that enforces it (23/08/2026)
- Baskets validated but never paid are deleted after 30 days, their postal address with them (23/08/2026)
- Orders are archived after 2 years and deleted after 10, counted from the close of the accounting year (23/08/2026)
- Deleting a basket now deletes the payment attached to it, the `OneToOne` carrying no cascade (23/08/2026)
- Added `archived` to `Basket`, and the back-office list shows the archived orders behind their own action only (23/08/2026)
- Added `BasketReminderService`, reminding the customers who left an order unpaid on the first and the seventh day (23/08/2026)
- Added `remindersSent` and `reminderConsent` to `Basket`, the count deliberately kept off `modification` (23/08/2026)
- `CoordinatesType` asks for the consent the reminder needs, the one box of the checkout that is not required (23/08/2026)
- Added the `basket_reminder` email template (23/08/2026)
- Added `label.archived`, `label.basket_reminder`, `label.basket_reminder_first`, `label.basket_reminder_second`, `label.basket_reminder_pay`, `label.basket_reminder_ignore` and `label.reminder_consent` to the `payment` catalogs (23/08/2026)
- Renamed `c975l:shop:baskets:delete` to `c975l:payment:baskets:retention`, which now archives as well as deletes (23/08/2026) [BC-Break]
- Added `c975l:payment:baskets:remind`, scheduled mid-morning rather than at night (23/08/2026)
- `BasketServiceInterface::deleteUnvalidated()` and `BasketService::UNVALIDATED_DAYS` moved to `BasketRetentionService` (23/08/2026) [BC-Break]
- The batched delete no longer clears the entity manager, which detached the baskets still to be removed (23/08/2026)
- Added `tests/Service/BasketRetentionServiceTest.php` and `tests/Service/BasketReminderServiceTest.php` (23/08/2026)
- Added a `config/whatsnew.json` entry for the reminders and the retention (23/08/2026)
- Added `GiftCard:Card`, a gift card drawn as one - UiBundle's flip card in the `credit-card` ratio, its visual full-bleed on the recto and mirrored on the verso (23/08/2026)
- Added `Contract\GiftCardDesign` and a fifth argument to `GiftCardService::issue()`, the visual the selling bundle hands over (23/08/2026)
- Added `designImage`, `designText` and `scratch` to `GiftCard`, copied at issuance so a card outlives the catalogue that sold it (23/08/2026)
- Added `GiftCard::$shareToken` and `GiftCardController`, the page whoever a card was bought for opens without an account (23/08/2026)
- The code is not written in that page: it is served by `gift_card_reveal` once the scratch panel is rubbed off, and refused on a card switched off (23/08/2026)
- The card's page is answered `noindex`, `Referrer-Policy: no-referrer` and `no-store` (23/08/2026)
- Added `assets/js/gift-card.js` and its lazy registration in the front barrel (23/08/2026)
- Added `sass/_gift-card.scss` and the `--gift-card-*` tokens it declares under a `payment-defaults` layer (23/08/2026)
- Added `GiftCard:Cards`, the cards of an order drawn on the customer's own order page (23/08/2026)
- `Basket:GiftCards` prints the address of each card beside its code, in the page and in the confirmation email (23/08/2026)
- `GiftCardCrudController` shows the card's address, for the day a customer loses the message it was sent in (23/08/2026)
- Added `label.gift_card_balance`, `label.gift_card_code_after_payment`, `label.gift_card_expired`, `label.gift_card_inactive`, `label.gift_card_no_expiry`, `label.gift_card_scratch_it`, `label.gift_card_share`, `label.gift_card_share_url`, `label.gift_card_show_back`, `label.gift_card_show_front`, `label.gift_card_spent`, `label.gift_card_test_mode`, `label.gift_card_valid_until` and `description.gift_card_how_to_use` to the `payment` catalogs (23/08/2026)
- Added `gift_card.reveal.error` to the three inline JS catalogues (23/08/2026)
- Added `tests/Controller/GiftCardControllerTest.php` and `tests/Assets/GiftCardMarkupTest.php`, and extended `GiftCardServiceTest` (23/08/2026)
- The README gained `The card as an object` and `The address the card is seen at`, and the two routes they add (23/08/2026)
- `UPGRADE.md` states the four new columns and the fifth argument of `issue()` (23/08/2026)
- Added the `gift_card_pdf` route and `templates/gift_card/pdf.html.twig`, the card cut out of an A4 at the size of a bank card (23/08/2026)
- The printed card carries its code, a file having no panel to rub off, and is refused on a card switched off (23/08/2026)
- The card's page and the customer's order page both offer it (23/08/2026)
- Added `label.gift_card_back`, `label.gift_card_download` and `description.gift_card_pdf` to the `payment` catalogs (23/08/2026)
- `composer.json` requires `c975l/core-bundle` `^1.15`, the version naming `PdfGeneratorInterface` (23/08/2026) [BC-Break]
- Added `EventSubscriber\CheckoutCspSubscriber`, completing the site's `form-action` with the active gateway's checkout, which a form navigation's redirection chain is checked against (22/08/2026)
- Added `PaymentGatewayInterface::getCheckoutDomains()`, the hosts a provider sends the payer to (22/08/2026) [BC-Break]
- Added `tests/EventSubscriber/CheckoutCspSubscriberTest.php` (22/08/2026)
- The "items shipped" page is no longer cached for an hour, reporting a write (22/08/2026)
- `Basket:Downloads` names the expiry date of each link and no longer claims the files outlive the email (22/08/2026)
- `BasketDownloadProviderInterface` rows carry `expiresAt`, and a provider mints no link of its own (22/08/2026) [BC-Break]
- Replaced `text.downloads_stay_available` with `text.downloads_same_as_email` and `text.download_expires_on` in the `payment` catalogs (22/08/2026)
- A payment is confirmed against what was left to pay, a discounted order no longer being refused (22/08/2026)
- The payment row records the discounted amount (22/08/2026)
- Added `label.send_items` and `label.send_counterparts` to the `payment` catalogs (22/08/2026)
- One case added to `tests/Service/BasketPaymentJourneyTest.php` (22/08/2026)
- The basket controller disables the add buttons outside the basket page too, a digital item already in the basket no longer being clickable (22/08/2026)
- The paid page carries the basket's downloads (22/08/2026)
- Added `Basket:Downloads`, shared by the paid page and the customer's order page (22/08/2026)
- Two cases added to `tests/Controller/BasketControllerTest.php` (22/08/2026)
- `BasketCodeService` accepts a code typed with spaces or dashes (22/08/2026)
- `Basket:DeleteLink` is a button and carries the `.delete-link` rule ShopBundle held (22/08/2026)
- The three quantity controls carry an `aria-label` naming the article (22/08/2026)
- Added `label.remove_item`, `label.decrease_quantity` and `label.increase_quantity` to the `payment` catalogs (22/08/2026)
- The code row's button drops its auto margin (22/08/2026)
- `.quantity-controls span` takes `--button-color` in place of a stated white (22/08/2026)
- Two cases added to `tests/Service/BasketCodeServiceTest.php` (22/08/2026)
- Added `EventSubscriber\BasketRecoverySubscriber`, a basket surviving the loss of its session (22/08/2026)
- Added `Basket::$recoveryToken`, a new column (22/08/2026) [BC-Break]
- `Basket::toArray()` no longer carries the three tokens (22/08/2026)
- Added `BasketRepository::findRecoverable()` and `findLastOpenByUser()` (22/08/2026)
- `BasketRepository::findUnvalidated()` reads the last change instead of the creation (22/08/2026)
- Added `BasketService::UNVALIDATED_DAYS`, the window the purge and the recovery cookie share (22/08/2026)
- `BasketCrudController` exports none of the three tokens (22/08/2026)
- Added `tests/EventSubscriber/BasketRecoverySubscriberTest.php` (22/08/2026)
- `Basket:ViewButton` carries one basket icon, the count as a pill and the total (22/08/2026)
- Removed `public/icons/eye.svg` (22/08/2026)
- Added `Basket:Shipping`, the delivery costs read from the configuration, and the `payment_shipping` block placing it on a page (22/08/2026)
- The basket says what is left to reach the free shipping, under the articles, the line following every change of the basket (22/08/2026)
- Validating a basket applies the code left in the field, and stays on the basket when it is refused (22/08/2026)
- `Handlers.formatAmount()` writes the amounts the controller rewrites with `Intl.NumberFormat`, replacing `getCurrencySymbol()` and its table of symbols (22/08/2026) [BC-Break]
- `Basket:Item` reads its `readonly` prop with `to_bool`, as `Basket:Items` does (22/08/2026)
- Added `VatCalculator` and the `payment_vat()` Twig function, the tax read back from the rates the lines were added with (22/08/2026)
- A line's `totalVat` carries the tax held in its price, no longer the rate multiplied by a quantity (22/08/2026) [BC-Break]
- The basket states its subtotal, its total including VAT and the tax it holds, the order and its email stating one line per rate (22/08/2026)
- The articles and the totals share one table, with column headers, one `<tbody>` per kind of item and a `<tfoot>` announced by `aria-live` (22/08/2026) [BC-Break]
- Removed the `kind` prop of `Basket:Display`, `Basket:Items`, `Basket:Item` and `Basket:Total`, and the untranslated VAT detail it drew (22/08/2026) [BC-Break]
- The stylesheet's `td`, `th` and `button span` rules are scoped to the basket's own table, where they sized every cell of the site (22/08/2026)
- The basket table stacks under 700px, the three figures lining up under the article (22/08/2026)
- Added `Basket:Empty`, said by the page, the table and the template the controller swaps in (22/08/2026)
- An article's picture is 80px, square and eager, and its description is cut at 80 characters (22/08/2026)
- Added `label.article`, `label.unit_price`, `label.subtotal`, `label.total_incl_vat`, `label.including_vat` and `label.including_vat_rate` to the three `payment` catalogs (22/08/2026)
- Added `tests/Service/VatCalculatorTest.php` (22/08/2026)
- Split `Basket:Shipping` in two, `Basket:FreeShipping` taking the basket and stating what is left to reach the free shipping (22/08/2026)
- A row hidden until it has something to say stays hidden under 700px, where the grid gave it its display back (22/08/2026)
- The article's cell is centred like the three figures beside it, and takes the whole row under 700px (22/08/2026)
- The totals are parted by a hairline and only the total keeps a rule of its own, the site drawing a thick one over every row of a foot (22/08/2026)
- Only the total is sized as one, the site sizing every cell of a foot that way (22/08/2026)
- An article's picture sits beside its name rather than over it, a row going from 202 to 105 pixels (22/08/2026)
- The picture stacks over the name again under 700px (22/08/2026)
- Added `label.shipping_free_missing` and `label.shipping_free_reached` to the three `payment` catalogs and to `assets/js/translations.js` (22/08/2026)
- Added `label.see_basket`, the block's four labels and the three shipping sentences to the `payment` catalogs (22/08/2026)
- Softened `label.share_order`, `label.share_order_help` and `label.share_explanation` (22/08/2026)
- Added `tests/Form/Block/ShippingBlockTypeTest.php` (22/08/2026)
- `Basket:TestMode` gives Stripe's test card while the payments are in test (22/08/2026)
- Added `label.test_mode_stripe_card` to the three `payment` catalogs (22/08/2026)
- Added the three news of the day to `config/whatsnew.json` (22/08/2026)
- `DiscountCrudController` translates the two kinds of code in the `payment` catalog (22/08/2026)

## v6.0.0

Complete rewrite: generic basket and checkout engine

- Added `tests/Controller/BasketControllerTest.php`, pinning the three ways out of `validate()`: the provider's checkout, and the basket page carrying a flash on each refusal (21/08/2026)
- Added `tests/Service/BasketShippingTest.php`, an order of two kinds only reaching "shipped" once both have gone out (21/08/2026)
- Added `tests/Management/WhatsNewProviderTest.php`, the one the other bundles carry, which also guards the three locales of `whatsnew.json` (21/08/2026)
- The README gained the `Usage` section the other bundles have: the twelve public routes, their urls and what each answers (21/08/2026)
- `composer.json` suggests `c975l/shop-bundle` and `c975l/crowdfunding-bundle`, the two bundles plugging sellable items in (21/08/2026)
- Added `config/whatsnew.json` and `Management\WhatsNewProvider`, this rewrite's five news reaching the dashboard's What's new in the three locales (21/08/2026)
- Added `tests/SkillsTest.php`, failing as soon as a skill names a class, route, config slug or command the sources no longer hold (21/08/2026)
- The three skills write their key source paths the way the other bundles do, and name a class of another package in full (21/08/2026)
- The README documents the three shipped skills and where an agent reads them from (21/08/2026)
- Removed `phpstan-baseline.neon`, as CoreBundle has none: of its 21 entries, 6 named code this rewrite deleted, 10 named errors fixed since, and 5 were live errors it was still hiding (21/08/2026)
- `PaymentFormFactoryInterface::create()` and `BasketServiceInterface::createForm()` answer `FormInterface`, the type the form factory actually returns (21/08/2026) [BC-Break]
- `Basket::$contentflags` and `Payment::$isFinished` drop the null their NOT NULL columns never hold (21/08/2026)
- `itemsShipped()` looks its basket up with `findOneBy()` rather than the magic `findOneByNumber()` (21/08/2026)
- Removed the dead `null !== $url` guard of `BasketController::validate()`, `validate()` always answering a url (21/08/2026)
- Rector caches in `.rector.cache` inside the repository, so a run no longer empties the cache of the other repositories, and `composer rector` drops `--clear-cache` (21/08/2026)
- Added `.stylelintrc.json` and `.markdownlint.json`, and `.codacy.yaml` and `eslint.config.mjs` are those of CoreBundle (21/08/2026)
- The CI workflow's comments are aligned on CoreBundle's, the `Modernisation` step no longer describing a scaffold this bundle does not have (21/08/2026)
- The README's licence badge points at `main`, the branch the repository actually has, instead of a 404 on `master` (21/08/2026)
- Removed the README's "bundle under development" warning, this release being tagged, tested and documented (21/08/2026)
- `.gitattributes` keeps the dev toolchain out of the Composer dist archive, as the other bundles do, `skills/` staying in (21/08/2026)
- Removed the paragraph UPGRADE.md said twice about a re-validated basket, the two giving opposite advice (21/08/2026)
- `paid()` refuses to deliver a basket that has something to pay and no payment recorded as finished (21/08/2026) [BC-Break]
- Added `skills/`, shipping `c975l-payment-checkout`, `c975l-payment-gateway` and `c975l-payment-items` to the agents of the sites installing this bundle (21/08/2026)
- Added `tests/Registry/BasketItemProviderRegistryTest.php`, `tests/Registry/BasketRecommendationRegistryTest.php`, `tests/Service/StylesheetProviderTest.php`, `tests/Scheduler/PaymentMaintenanceTaskProviderTest.php` and `tests/Form/PaymentFormFactoryTest.php` (21/08/2026)
- The README requires `php` in `>=8.4` and names `c975l/core-bundle` instead of ConfigBundle and UiBundle (21/08/2026)
- The README's contents list the gateway verification and the customer area downloads (21/08/2026)
- Removed the commented-out `make:entity` boilerplate of `BasketRepository` and `PaymentRepository` (21/08/2026)
- `applyNotification()` delivers the basket it settles instead of only flagging its payment (21/08/2026)
- Added `Contract\ReturnAwareGatewayInterface` and `StripeGateway::readReturn()`, confirming the customer's return with the provider (21/08/2026)
- `checkout.session.completed` no longer delivers a session Stripe reports as unpaid (21/08/2026)
- Added `BasketRepository::claimPaid()`, moving a basket from "validated" to "paid" in one conditional statement (21/08/2026)
- `PaymentNotification` carries the amount the provider charged (21/08/2026)
- `applyNotification()` drops a notification whose amount does not match the basket (21/08/2026)
- `Basket:PaidInfos` takes a `confirmed` prop, thanking only a customer whose payment is confirmed (21/08/2026)
- A notification naming an unknown basket is logged and dropped rather than raised (21/08/2026)
- Added `Gateway\StripeSessionReader`, deciding on the payload alone whether Stripe reports the money as arrived (21/08/2026)
- Reading a session no longer fetches its `PaymentIntent` (21/08/2026)
- An edited basket calls off the checkout it had already opened (21/08/2026)
- Added `Contract\ExpirableGatewayInterface` and `Contract\CheckoutSession`, `createCheckout()` answering the url and the provider's reference (21/08/2026) [BC-Break]
- `Payment` gained a `gateway_reference` column, held until the payment is settled or the checkout called off (21/08/2026) [BC-Break] **Needs db migration** see [UPGRADE.md](UPGRADE.md)
- A provider hands its pre-payment data over at `onBasketValidated()` and gets it back at `onBasketPaid()`, `Basket` keeping it in a new `checkout_data` column (21/08/2026) [BC-Break] **Needs db migration** see [UPGRADE.md](UPGRADE.md)
- `Basket::$checkoutData` is dropped as soon as the basket is delivered or its checkout called off (21/08/2026)
- `validate()` flushes after the provider loop (21/08/2026)
- A basket edited after it was validated goes back to "new", `addItem()` and `deleteItem()` reopening it (21/08/2026)
- An order the session still names is no longer added to nor taken from, the visitor starting a new basket (21/08/2026)
- `createPayment()` writes the basket's own payment row over rather than creating a second one (21/08/2026)
- `PaymentWebhookController` answers "Webhook failed" instead of the exception's own message (21/08/2026)
- Added `tests/Service/BasketPaymentJourneyTest.php` and `tests/Gateway/StripeSessionReaderTest.php` (21/08/2026)
- Removed `Command\MigrateLegacyTablesCommand` and its README section (21/08/2026) [BC-Break]
- Added the `audit-deps` Composer script, first step of `qa` and its own CI step (21/08/2026)
- Upgraded `easycorp/easyadmin-bundle` from v5.4.1 to v5.5.1 (GHSA-g2fm-8hr4-j82h) (21/08/2026)
- Every label of this bundle is read from its own `payment` catalog instead of ShopBundle's `shop` domain (21/08/2026) [BC-Break]
- The `payment` catalogs carry the 62 keys that move with it, `label.info_basket` and `label.info_payment` newly written (21/08/2026)
- Added `tests/TranslationDomainTest.php`, failing on a foreign domain and on a key missing from a locale (21/08/2026)
- Added `Management\PaymentStatusProvider`, reporting the orders left to ship, the stalled payments, the gateway and the test mode (21/08/2026)
- Added `Contract\VerifiableGatewayInterface` and `Management\GatewayHealthCheckProvider`, telling a revoked key from a working one (21/08/2026)
- `StripeGateway` implements it, a gateway that does not being reported as skipped (21/08/2026)
- `BasketService` looks its basket up with `find()` rather than the magic `findOneById()` (21/08/2026)
- `BasketService` answers null for an empty session rather than querying on it (21/08/2026)
- Added the customer area, `/account/orders` listing a buyer's paid orders and `/account/orders/{number}` showing one (21/08/2026)
- Added `Contract\BasketDownloadProviderInterface` and `Registry\BasketDownloadRegistry`, offering bought files for download again (21/08/2026)
- Added `BasketRepository::findPaidByUser()`, listing the "paid" and "shipped" baskets of a user, newest first (21/08/2026)
- The order page answers a basket that is not the asking user's own as missing rather than as forbidden (21/08/2026)
- `Basket:Delivery`, `Basket:DigitalItems` and `Basket:TrackOrder` read their content flags off `PaymentBundle\Entity\Basket` (21/08/2026)
- Added `Management\LinkableRouteProvider`, offering the basket page and the order history as SiteBundle Menu targets (21/08/2026)
- Added the linkable routes' labels to the three `payment` catalogs (21/08/2026)
- `ManagementTargetsTest` scans `src/Controller` too (21/08/2026)
- Removed the 33 orphan keys of the three `payment` catalogs, 80 units down to 47 in each locale (21/08/2026) [BC-Break]
- Added `tests/ConfigsJsonTest.php`, guarding slugs, ConfigBundle's keys, `choice` values and the label translations (21/08/2026)
- The four transactional emails are sent through UiBundle's `EmailService` with `wrapLayout: true` (21/08/2026)
- Removed `Service\EmailService` and `Service\EmailServiceInterface` (21/08/2026) [BC-Break]
- Removed `templates/emails/layout.html.twig`, made redundant by `wrapLayout: true` (21/08/2026)
- Added `Email\BasketEmailFactory`, building the `EmailSendRequest` the basket emails share (21/08/2026)
- `ConfirmOrderMessageHandler` and `ItemsShippedMessageHandler` throw on a failed send, so Messenger retries (21/08/2026)
- The two message handlers look their basket up with `find()` rather than the magic `findOneById()` (21/08/2026)
- Added `tests/MessageHandler/EmailRetryTest.php` (21/08/2026)
- Added `tests/Email/BasketEmailFactoryTest.php` (21/08/2026)
- Removed `templates/fragments/merchantData.html.twig` (21/08/2026) [BC-Break]
- Added `templates/emails/layout.html.twig`, the four transactional emails extending a template that did not exist (21/08/2026)
- Removed `templates/emails/paymentDone.html.twig` and `templates/emails/errorValidation.html.twig`, which nothing dispatched (21/08/2026) [BC-Break]
- Every provider is asked whether its entries can still be ordered at the top of `validate()` (20/08/2026)
- Added `BasketItemProviderInterface::validateCheckout()`, which every provider must implement (20/08/2026) [BC-Break]
- Added `BasketNotOrderableException`, caught by `BasketController::validate()` and shown as the provider's own message (20/08/2026)
- `label.test_mode` speaks of the charge, not of the shop (20/08/2026)
- `Basket:Validation` repeats `Basket:TestMode` above the pay button, reading `payment-test-mode` alone (20/08/2026)
- Added `Basket:SecurePayments`, the payment badge shown when `payment-gateway` names Stripe (20/08/2026)
- Added `label.secure_payments_stripe` to the three `payment` catalogs (20/08/2026)
- Added `Management\PaymentAlertProvider`, the dashboard alert for an active gateway holding no usable key (20/08/2026)
- `BasketService::validate()` throws `PaymentUnavailableException` on it, the basket page saying so instead of the provider's 500 (20/08/2026)
- Added `PaymentGatewayRegistry::getActiveOrNull()` (20/08/2026)
- Added `flash.payment_unavailable` and the alert's labels to the `payment` translations (20/08/2026)
- Removed `Item:Quantity`, the basket count each item of a listing carried (20/08/2026) [BC-Break]
- `assets/controllers.js` imports the basket controller on demand, for a document carrying `data-controller="basket"` (20/08/2026)
- `assets/js/handlers.js` borrows `getLanguage()` and `translate()` from UiBundle instead of copying them (20/08/2026) [BC-Break]
- The three `translations.*.js` are one `translations.js` keyed by locale (20/08/2026) [BC-Break]
- `getCurrencySymbol()` returns the currency code for a currency it does not list, instead of `" undefined"` (20/08/2026)
- The basket controller removes its `basket:update` listener on disconnect, a Turbo navigation having left one copy behind per visit (20/08/2026)
- The basket controller dispatches through Stimulus, so the instance that already updated itself no longer replays its own event (20/08/2026)
- The three basket calls share one `send()`, and a failed response no longer draws its message twice (20/08/2026)
- The timezone is sent once per browsing session, however many basket controllers the pages visited carry (20/08/2026)
- Removed `updateBasketButtonDisplay()`, which nothing called, and the `message` target nothing read (20/08/2026) [BC-Break]
- `assets/controllers.js` starts its own Stimulus app instead of exporting `register()` (20/08/2026) [BC-Break]
- Added `Service\ScriptProvider` and `Management\ImportmapProvider`, the basket controller being loaded on its own (20/08/2026)
- `BasketService::paid()` stamps `modification` with a `DateTime`, the column being mutable (20/08/2026)
- Removed the eight templates no controller rendered, which called routes this bundle no longer declares (20/08/2026) [BC-Break]
- Removed `StripeErrorMessage`, its handler, `EmailService::stripeErrorMessage()` and the two error templates, which nothing dispatched (20/08/2026) [BC-Break]
- Stripe sits behind `Contract\PaymentGatewayInterface`, `Gateway\StripeGateway` being the only class importing `Stripe\*` (20/08/2026)
- Added `Registry\PaymentGatewayRegistry` and the `payment-gateway` config naming the provider the site charges with (20/08/2026)
- Added `payment-test-mode`, `stripe-secret-test` and `stripe-webhook-secret-test` to `config/configs.json` (20/08/2026)
- Added the test payments tile to the dashboard, toggling `payment-test-mode` (20/08/2026)
- Added `Basket:TestMode`, the banner the basket pages carry while the payments are in test (20/08/2026)
- The basket page draws this bundle's own banner instead of ShopBundle's component (20/08/2026)
- The order number's `TEST-` prefix follows `payment-test-mode` instead of the word "test" in the secret key (20/08/2026)
- `BasketService::createStripeSession()` is now `createCheckout()`, returning the redirect url alone (20/08/2026) [BC-Break]
- `BasketService::processStripePayment()` is now `applyNotification()`, taking a `PaymentNotification` (20/08/2026) [BC-Break]
- `StripeWebhookController` is now `PaymentWebhookController`, serving `/payment/webhook/{gateway}` and still answering on `/shop/stripe/webhook` (20/08/2026) [BC-Break]
- `Payment::$stripeToken` and `Payment::$stripeMethod` are now `$transactionId` and `$paymentMethod`, next to a new `$gateway` (20/08/2026) [BC-Break] **Needs db migration** see [UPGRADE.md](UPGRADE.md)
- The payment CRUD links a transaction to the provider's live dashboard unless the site is in test mode (20/08/2026)
- The payment CRUD labels its provider columns from the `payment` catalog instead of `shop` (20/08/2026)
- Added `label.gateway`, `label.transaction_id`, `label.payment_method`, `label.test_mode`, the two `label.payment_test_mode_*` and the two `flash.payment_test_mode_*` to the three `payment` catalogs (20/08/2026)
- Added `tests/Gateway`, `tests/Registry`, `tests/Management` and `tests/Service/PaymentTestModeTest.php` (20/08/2026)
- The baskets and the payments export as SQL/CSV/JSON from their CRUD index, the basket's security token excluded (20/08/2026)
- The README lists the exports (20/08/2026)
- `BasketCrudController` takes `AdminUrlGeneratorInterface` rather than the final `AdminUrlGenerator` (20/08/2026)
- The basket's icons and its `no-product-image.webp` are served from this bundle instead of `bundles/c975lshop/images/` (19/08/2026)
- Added `sass/`, its compiled `public/css/styles.min.css` and `Service\StylesheetProvider`, carrying the basket rules that lived in ShopBundle (19/08/2026)
- The README documents the assets to install and the stylesheet it now ships (19/08/2026)
- Composer's archive cache is carried from one CI run to the next (17/08/2026)
- The CI workflow runs on a push to main and on pull requests only, under a `concurrency` group cancelling superseded runs (17/08/2026)
- Removed `COMPOSER_TOKEN` from the setup-php step, which never reached the archive downloads (17/08/2026)
- The workflow's `GITHUB_TOKEN` is pinned to `contents: read` (17/08/2026)
- The Codacy token is declared on the job rather than on its own step, whose `if` could not read it (17/08/2026)
- The templates state their page summary as `summarySocialNetwork`, the name both layouts read (13/08/2026)
- The `Standard Symfony` step, absent from the workflow, now runs in the CI (03/08/2026)
- Added the `qa` Composer script and its steps, which the CI workflow now calls (03/08/2026)
- Added `bin/ci.sh`, replaying the CI checks on dependencies freshly resolved from Packagist (03/08/2026)
- Removed `templates/layout.html.twig`, a standalone Bootstrap 3 shell loading its assets through `inc_lib()` (02/08/2026) [BC-Break]
- The five templates that extended it now extend the app's `layout.html.twig`, and declare `content` instead of `payment_content` (02/08/2026) [BC-Break]
- The `localizedcurrency` filter is replaced by `format_currency` in the seven templates still calling it (02/08/2026)
- Removed every `c975L/ToolbarBundle` call, along with `templates/tools.html.twig`, which only existed for them (02/08/2026) [BC-Break]
- The dashboard's per-row link is a `<twig:c975LUi:Button:Button>` instead of `toolbar_button_text()` (02/08/2026)
- Added `label.cancel`, `label.dashboard` and `label.validate` to the three `payment` catalogs (02/08/2026)
- Added `PaymentMaintenanceTaskProvider`, declaring `c975l:shop:baskets:delete` so a site no longer lists it in its own schedule (01/08/2026)
- Raised the `c975l/config-bundle` requirement to `^5.16`, for `MaintenanceTaskProviderInterface` (01/08/2026)
- `php` is now required in `>=8.4` instead of `>=8.0` (30/07/2026) [BC-Break]
- The `symfony/*` requirements are now constrained to `^8.0` instead of `*` (30/07/2026) [BC-Break]
- The third-party requirements left in `*` are now bounded on their installed version (30/07/2026)
- The `c975l/*` requirements are now bounded on their major (30/07/2026)
- `Basket::$user` and `Payment::$user` are now typed `c975L\ConfigBundle\Contract\UserInterface` instead of `App\Entity\User` (30/07/2026) [BC-Break]
- Added `.codacy.yaml`, `phpcs.xml.dist` and `eslint.config.mjs` (30/07/2026)
- Applied PSR-12 to the codebase (30/07/2026)
- Added `.php-cs-fixer.dist.php`, applying the Symfony coding standards (30/07/2026)
- Added `phpstan.dist.neon`, running the static analysis at level 5 (30/07/2026)
- Added `phpstan-baseline.neon`, freezing the errors that predate the analysis (30/07/2026)
- Added the `CI` GitHub Actions workflow, running PSR-12, the static analysis, the tests and the coverage upload (30/07/2026)
- Removed the `site-url` config entry and its translations, now declared by ConfigBundle (29/07/2026) [BC-Break]
- Added the Codacy grade badge to the README (30/07/2026)
- Rewritten as the generic Basket/checkout engine, replacing the action-based Payment form, voter and Stripe services (22/07/2026)
- `Basket`, `Payment` and the Stripe checkout/webhook flow moved in from ShopBundle, same table names, no data migration (22/07/2026)
- Added `BasketItemProviderInterface`, through which a satellite bundle plugs its own sellable items into the basket (22/07/2026)

## v5.0.5

- Corrected Services (05/11/2024)

## v5.0.4

- Corrected Voter (05/11/2024)

## v5.0.3

- Update composer.json (05/11/2024)

## v5.0.2

## v5.0.1

## v5.0

- Changed to new recomended bundle SF 7 structure (05/11/2024)

Upgrading from v4.x? **Check UPGRADE.md**

## v4.0

- Changed `localizeddate` to `format_datetime` (11/10/2021)

Upgrading from v2.x? **Check UPGRADE.md**

## v3.5

- Changed `Symfony\Component\Translation\TranslatorInterface` to `Symfony\Contracts\Translation\TranslatorInterface` (03/09/2021)

## v3.4

- Removed versions constraints in composer (03/09/2021)

## v3.3.1

- Cosmetic changes due to Codacy review (04/03/2020)

## v3.3

- Removed use of symplify/easy-coding-standard as abandonned (19/02/2020)

## v3.2.1

- Added attribut title (19/01/2020)

## v3.2

- Changed doctrine-bundle version (18/12/2019)

## v3.1.1

- Resized images to decrease downloaded size (28/11/2019)

## v3.1

- Replaced `_locale` by `locale` (15/07/2019)
- Made use of apply spaceless (05/08/2019)

## v3.0.1

- Corrected composer.json (15/07/2019)

## v3.0

- Made use of c975LEmailBundle v3 which use Symfony/Mailer (15/07/2019)
- Drop support of Symfony 3.x (15/07/2019)

Upgrading from v2.x? **Check UPGRADE.md**

## v2.x

## v2.1.1.2

- Changed file rights (15/07/2019)

## v2.1.1.1

- Changed Github's author reference url (08/04/2019)

## v2.1.1

- Made use of Twig namespace (11/03/2019)
- Added declaration of formFactory (11/03/2019)
- Added condition to check if $emailData is set (11/03/2019)
- Corrected error ofr errMessage (11/03/2019)

## v2.1

- Modified Entity to specify lengths for strings (15/02/2019)
- Modified Entity to use typehint (15/02/2019)
- Documented the possibility to use `php bin/console make:migration` (15/02/2019)

## v2.0.6

- Removed deprecations for @Method (13/02/2019)
- Implemented AstractController instead of Controller (13/02/2019)
- Modified Dependencyinjection rootNode to be not empty (13/02/2019)

## v2.0.5

- Modified required versions in `composer.json` (25/12/2018)

## v2.0.4

- Added missing use (25/12/2018)

## v2.0.3

- Added rector to composer dev part (23/12/2018)
- Modified required versions in composer (23/12/2018)
- Made use of `??` (25/12/2018)

## v2.0.2.1

- Suppressed field `site` from `bundle.yaml` file (04/12/2018)

## v2.0.2

- Corrected `UPGRADE.md` for `php bin/console config:create` (03/12/2018)
- Made use of parameter `c975LCommon.site` in place of `c975LContactForm.site` (04/12/2018)

## v2.0.1

- Updated `README.md` (01/09/2018)
- Fixed 2 "." in getParameter (01/09/2018)

## v2.0

- Created branch 1.x (31/08/2018)
- Updated composer.json (01/09/2018)
- Added `UPGRADE.md` (01/09/2018)
- Added `bundle.yaml` (01/09/2018)
- Removed declaration of parameters in Configuration class as they are end-user parameters and defined in c975L/ConfigBundle (01/09/2018)
- Added Route `payment_config` (01/09/2018)
- Added shortcut `$paymentService->getParameter()` (01/09/2018)
- Removed calls of `$container->getParameter()` (01/09/2018)

Upgrading from v1.x? **Check UPGRADE.md**

## v1.x

## v1.16.2

- Fixed Voter constants (31/08/2018)

## v1.16.1

- Changed the FormFactory to the right version and made use of it (27/08/2018)
- Removed SubmitType from `PaymentType` (27/08/2018)
- Added IP address to `PaymentType` (27/08/2018)
- Added gdpr as config value (27/08/2018)
- Suppressed un-needed translation (27/08/2018)

## v1.16

- Removed 'true ===' as not needed (25/08/2018)
- Updated `README.md` to give the cas the validation after payment fails (25/08/2018)
- Added dependency on "c975l/config-bundle" and "c975l/services-bundle" (26/08/2018)
- Added translations for `errorStripe` email template (27/08/2018)
- Removed un-needed services (27/08/2018)
- Added a link to payment_display Route in email sent (27/08/2018)

## v1.15.2

- Replaced links in dashboard (for purchased) by buttons (25/08/2018)

## v1.15.1.1

- Added missing documentation (25/08/2018)

## v1.15.1

- Corrected Dashboard (25/08/2018)

## v1.15

- Added link to BuyMeCoffee (24/08/2018)
- Added link to apidoc (24/08/2018)
- Removed FQCN (24/08/2018)
- Added documentation (24/08/2018)
- Update `README.md` (24/08/2018)
- Corrected undefined variable in `PaymentType` (24/08/2018)
- Made controller skinny (24/08/2018)
- Split Service in multiple files (24/08/2018)
- Suppressed `reUse()` method as not used (24/08/2018)

## v1.14.1

- Added PaymentServiceInterface to work with other projects waiting its refundation (23/08/2018)

## v1.14

- Made use of Voters for access rights (01/08/2018)

## v1.13.1.1

- Removed property $roleNeeded as not needed (30/07/2018)

## v1.13.1

- Injected `AuthorizationCheckerInterface` in Controllers to avoid use of `$this->get()` (30/07/2018)
- Made use of ParamConverter (30/07/2018)
- Removed Route payment_confirm (30/07/2018)

## v1.13

- Added `_locale` variable in sendMail (29/07/2018)
- Added `stripeFeePercentage` and `stripeFeeFixed` as config values (29/07/2018)

## v1.12

- Removed required in composer.json (22/05/2018)
- Added message for InvalidArgumentException (24/07/2018)
- Removed 'Action' in Controllers method as not requested anymore (24/07/2018)
- Use of Yoda-style (24/07/2018)
- Moved code from Controller > charge() to Service > charge() to keep only glue code in controller, and split it multiples methods (24/07/2018)
- Added Controller for auto-wire (26/07/2018)
- Removed toolbar when user has not signed in in payment display (26/07/2018)

## v1.11.5

- Modified toolbars calls due to modification of c975LToolbarBundle (13/05/2018)

## v1.11.4

- Replaced submit button by `SubmitType` (16/04/2018)

## v1.11.3

- Corrected translations (02/04/2018)

## v1.11.2

- Added warning in email sent about test payments (02/04/2018)

## v1.11.1

- Changed title for payment (26/03/2018)

## v1.11

- Added Possibility to pass a VAT to payment to indicate in the display and email (21/03/2018)
- Added VAT config value for direct payments (21/03/2018)
- Changed amount from mediumint to int in case of ;-) (21/03/2018)

## v1.10.1

- Removed unuseful `strtoupper` (21/03/2018)
- Corrected Route `payment_free_amount` (21/03/2018)
- Added return to `payment_display` if no `returnRoute` is defined (21/03/2018)

## v1.10

- Added `returnRoute` to paymentData as it can't work if there are more than one Bundle using c975LPaymentBundle, it has to be definedat each payment [BC-Break] (20/03/2018)
- Removed `setFinished` from `chargeAction()` method as it has to be set when the action has been done (20/03/2018)
- Added Repository class (20/03/2018)
- Corrected missing `%site%` information in `order.html.twig` template
- Updated `README.md` for example to redirect, by default, to Route `payment_display` in place of `NotFound` if `returnRoute` is called again after payment finished (20/03/2018)
- Added Toolbar on `order.html.twig` (20/03/2018)
- Suppressed Route `payment_confirm` and merged with `payment_display` as they had almost the same goal (20/03/2018)

## v1.9.1

- Set currency to be uppercase in DB and Entity (19/03/2018)

## v1.9

- Added button and link payments (19/03/2018)
- Added free amount payment (19/03/2018)
- Updated `README.md` (19/03/2018)
- Added `defaultCurrency` config option (19/03/2018)

## v1.8.2

- Suppressed site + email info sent from Controller for c975L/EmailBundle as theyr are set in Twig overriding `layout.html.twig` (17/03/2018)

## v1.8.1

- Added site mention in explanation message sent by email (08/03/2018)

## v1.8

- Simplified method to be written on the site side part, by moving parts of it to Route `payment_charge` (07/03/2018)
- Added a template, for email, to be overriden and that should contain merchant's data, such as address, VAT number, etc.
- Added text to wait page loading after payment (07/03/2018)
- Added a different text in email sent for user and merchant (07/03/2018)
- Suppressed translations for email taken from c975L/EmailBundle (07/03/2018)

## v1.7

- Suppressed Twig extension to replace by just include the html fragment, to be coherent with other c975L Bundles (06/03/2018)

## v1.6

- Added "_locale requirement" part for multilingual prefix in `routing.yml` in `README.md` (04/03/2018)
- Corrected `test` variable to `live` (05/03/2018)
- Modified `setDataFromArray()` in Entity (05/03/2018)
- Added the possibility to test products, so to use test keys, while being live for other products (05/03/2018)
- Added data to test payment in warning panel (05/03/2018)

## v1.5.2

- Corrected source and issues in `composer.json` (04/03/2018)
- Corrected `README.md` (04/03/2018)

## v1.5.1.1

- Removed "|raw" in call of `payment_system()` (01/03/2018)

## v1.5.1

- Added 'is_safe' to Twig extension `PaymentSystem` to remove "|raw" on each call (01/03/2018)

## v1.5

- Abandoned Glyphicon and replaced by fontawesome (22/02/2018)
- Added c957L/IncludeLibrary to include libraries in layout.html.twig (27/02/2018)
- Removed email layout and styles to use those defined in c975L\EmailBundle (27/02/2018)

## v1.4.2

- Corrected warning display when using test keys on payment form (21/02/2018)

## v1.4.1

- Modified payment page (19/02/2018)

## v1.4

- Change about composer download in `README.md` (04/02/2018)
- Add support in `composer.json`+ use of ^ for versions request (04/02/2018)
- Add Routes to display payments (05/02/2018)
- Renamed Route `payment_display`  to `payment_form` to allow the one to display (05/02/2018)
- Renamed Route `payment_order`  to `payment_confirm` and changed its url (05/02/2018)
- Added roleNeeded as config value to display payments (05/02/2018)
- Renamed Service `StripePaymentService.php` to `PaymentService.php` (05/02/2018)
- Renamed Entity `StripePayment.php` to `Payment.php` (05/02/2018)
- Created a method in Service to get the keys (05/02/2018)
- Updated `README.md` (17/02/2018)
- Corrected `PaymentService.php` (17/02/2018)
- Improvement of `payment_form` (18/02/2018)
- Removed Stripe logos and replaced by a Twig extension (18/02/2018)
- Removed "<![CDATA[]]>" unused in xlf files (18/02/2018)

## v1.3.2

- Add of a else case in the `README.md` for refresh on stopped loading order page (02/02/2018)

## v1.3.1

- Change in `README.md` to redirect after payment in place of displaying Twig template (02/02/2018)
- Add of a Route to display order data (02/02/2018)

## v1.3

- remove of bitcoin option as it will not be supported anymore by Stripe as of 04/23 (01/02/2018)

## v1.2.1

- Changes in `README.md` (01/02/2018)

## v1.2

- Add of Timezone config as microtime use UTC (17/08/2017)
- Creation of a `reUse()` method to create payment form for a payment initiated but not executed (17/08/2017)

## v1.1

- Remove of .travis.yml as tests have to be defined before (01/08/2017)
- Add of code files (16/08/2017)

## v1.0

- Creation of bundle (08/07/2017)
