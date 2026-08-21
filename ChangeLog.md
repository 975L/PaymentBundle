# Changelog

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
