# Changelog

v2.0.4
------
- Added missing use (25/12/2018)

v2.0.3
------
- Added rector to composer dev part (23/12/2018)
- Modified required versions in composer (23/12/2018)
- Made use of `??` (25/12/2018)

v2.0.2.1
--------
- Suppressed field `site` from `bundle.yaml` file (04/12/2018)

v2.0.2
------
- Corrected `UPGRADE.md` for `php bin/console config:create` (03/12/2018)
- Made use of parameter `c975LCommon.site` in place of `c975LContactForm.site` (04/12/2018)

v2.0.1
------
- Updated `README.md` (01/09/2018)
- Fixed 2 "." in getParameter (01/09/2018)

v2.0
----
**Upgrading from v1.x? Check UPGRADE.md**
- Created branch 1.x (31/08/2018)
- Updated composer.json (01/09/2018)
- Updated `README.md` (01/09/2018)
- Added `UPGRADE.md` (01/09/2018)
- Added `bundle.yaml` (01/09/2018)
- Removed declaration of parameters in Configuration class as they are end-user parameters and defined in c975L/ConfigBundle (01/09/2018)
- Added Route `payment_config` (01/09/2018)
- Added shortcut `$paymentService->getParameter()` (01/09/2018)
- Removed calls of `$container->getParameter()` (01/09/2018)


v1.x
====

v1.16.2
-------
- Fixed Voter constants (31/08/2018)

v1.16.1
-------
- Changed the FormFactory to the right version and made use of it (27/08/2018)
- Removed SubmitType from `PaymentType` (27/08/2018)
- Added IP address to `PaymentType` (27/08/2018)
- Added gdpr as config value (27/08/2018)
- Suppressed un-needed translation (27/08/2018)

v1.16
-----
- Removed 'true ===' as not needed (25/08/2018)
- Updated `README.md` to give the cas the validation after payment fails (25/08/2018)
- Added dependency on "c975l/config-bundle" and "c975l/services-bundle" (26/08/2018)
- Added translations for `errorStripe` email template (27/08/2018)
- Removed un-needed services (27/08/2018)
- Added a link to payment_display Route in email sent (27/08/2018)

v1.15.2
-------
- Replaced links in dashboard (for purchased) by buttons (25/08/2018)

v1.15.1.1
---------
- Added missing documentation (25/08/2018)

v1.15.1
-------
- Corrected Dashboard (25/08/2018)

v1.15
-----
- Added link to BuyMeCoffee (24/08/2018)
- Added link to apidoc (24/08/2018)
- Removed FQCN (24/08/2018)
- Added documentation (24/08/2018)
- Update `README.md` (24/08/2018)
- Corrected undefined variable in `PaymentType` (24/08/2018)
- Made controller skinny (24/08/2018)
- Split Service in multiple files (24/08/2018)
- Suppressed `reUse()` method as not used (24/08/2018)

v1.14.1
-------
- Added PaymentServiceInterface to work with other projects waiting its refundation (23/08/2018)

v1.14
-----
- Made use of Voters for access rights (01/08/2018)

v1.13.1.1
---------
- Removed property $roleNeeded as not needed (30/07/2018)

v1.13.1
-------
- Injected `AuthorizationCheckerInterface` in Controllers to avoid use of `$this->get()` (30/07/2018)
- Made use of ParamConverter (30/07/2018)
- Removed Route payment_confirm (30/07/2018)

v1.13
-----
- Added `_locale` variable in sendMail (29/07/2018)
- Added `stripeFeePercentage` and `stripeFeeFixed` as config values (29/07/2018)

v1.12
-----
- Removed required in composer.json (22/05/2018)
- Added message for InvalidArgumentException (24/07/2018)
- Removed 'Action' in Controllers method as not requested anymore (24/07/2018)
- Use of Yoda-style (24/07/2018)
- Moved code from Controller > charge() to Service > charge() to keep only glue code in controller, and split it multiples methods (24/07/2018)
- Added Controller for auto-wire (26/07/2018)
- Removed toolbar when user has not signed in in payment display (26/07/2018)

v1.11.5
-------
- Modified toolbars calls due to modification of c975LToolbarBundle (13/05/2018)

v1.11.4
-------
- Replaced submit button by `SubmitType` (16/04/2018)

v1.11.3
-------
- Corrected translations (02/04/2018)

v1.11.2
-------
- Added warning in email sent about test payments (02/04/2018)

v1.11.1
-------
- Changed title for payment (26/03/2018)

v1.11
-----
- Added Possibility to pass a VAT to payment to indicate in the display and email (21/03/2018)
- Added VAT config value for direct payments (21/03/2018)
- Changed amount from mediumint to int in case of ;-) (21/03/2018)

v1.10.1
-------
- Removed unuseful `strtoupper` (21/03/2018)
- Corrected Route `payment_free_amount` (21/03/2018)
- Added return to `payment_display` if no `returnRoute` is defined (21/03/2018)

v1.10
-----
- Added `returnRoute` to paymentData as it can't work if there are more than one Bundle using c975LPaymentBundle, it has to be definedat each payment [BC-Break] (20/03/2018)
- Removed `setFinished` from `chargeAction()` method as it has to be set when the action has been done (20/03/2018)
- Added Repository class (20/03/2018)
- Corrected missing `%site%` information in `order.html.twig` template
- Updated `README.md` for example to redirect, by default, to Route `payment_display` in place of `NotFound` if `returnRoute` is called again after payment finished (20/03/2018)
- Added Toolbar on `order.html.twig` (20/03/2018)
- Suppressed Route `payment_confirm` and merged with `payment_display` as they had almost the same goal (20/03/2018)

v1.9.1
------
- Set currency to be uppercase in DB and Entity (19/03/2018)

v1.9
----
- Added button and link payments (19/03/2018)
- Added free amount payment (19/03/2018)
- Updated `README.md` (19/03/2018)
- Added `defaultCurrency` config option (19/03/2018)

v1.8.2
------
- Suppressed site + email info sent from Controller for c975L/EmailBundle as theyr are set in Twig overriding `layout.html.twig` (17/03/2018)

v1.8.1
------
- Added site mention in explanation message sent by email (08/03/2018)

v1.8
----
- Simplified method to be written on the site side part, by moving parts of it to Route `payment_charge` (07/03/2018)
- Added a template, for email, to be overriden and that should contain merchant's data, such as address, VAT number, etc.
- Added text to wait page loading after payment (07/03/2018)
- Added a different text in email sent for user and merchant (07/03/2018)
- Suppressed translations for email taken from c975L/EmailBundle (07/03/2018)

v1.7
----
- Suppressed Twig extension to replace by just include the html fragment, to be coherent with other c975L Bundles (06/03/2018)

v1.6
----
- Added "_locale requirement" part for multilingual prefix in `routing.yml` in `README.md` (04/03/2018)
- Corrected `test` variable to `live` (05/03/2018)
- Modified `setDataFromArray()` in Entity (05/03/2018)
- Added the possibility to test products, so to use test keys, while being live for other products (05/03/2018)
- Added data to test payment in warning panel (05/03/2018)

v1.5.2
------
- Corrected source and issues in `composer.json` (04/03/2018)
- Corrected `README.md` (04/03/2018)

v1.5.1.1
--------
- Removed "|raw" in call of `payment_system()` (01/03/2018)

v1.5.1
------
- Added 'is_safe' to Twig extension `PaymentSystem` to remove "|raw" on each call (01/03/2018)

v1.5
----
- Abandoned Glyphicon and replaced by fontawesome (22/02/2018)
- Added c957L/IncludeLibrary to include libraries in layout.html.twig (27/02/2018)
- Removed email layout and styles to use those defined in c975L\EmailBundle (27/02/2018)

v1.4.2
------
- Corrected warning display when using test keys on payment form (21/02/2018)

v1.4.1
------
- Modified payment page (19/02/2018)

v1.4
----
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

v1.3.2
------
- Add of a else case in the `README.md` for refresh on stopped loading order page (02/02/2018)

v1.3.1
------
- Change in `README.md` to redirect after payment in place of displaying Twig template (02/02/2018)
- Add of a Route to display order data (02/02/2018)

v1.3
----
- remove of bitcoin option as it will not be supported anymore by Stripe as of 04/23 (01/02/2018)

v1.2.1
------
- Changes in `README.md` (01/02/2018)

v1.2
----
- Add of Timezone config as microtime use UTC (17/08/2017)
- Creation of a `reUse()` method to create payment form for a payment initiated but not executed (17/08/2017)

v1.1
----
- Remove of .travis.yml as tests have to be defined before (01/08/2017)
- Add of code files (16/08/2017)

v1.0
----
- Creation of bundle (08/07/2017)
