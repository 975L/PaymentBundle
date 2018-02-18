# Changelog

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
- Creation of a `reUse()` method to create payment form for a payment initiated but not executed

v1.1
----
- Remove of .travis.yml as tests have to be defined before (01/08/2017)
- Add of code files (16/08/2017)

v1.0
----
- Creation of bundle (08/07/2017)