---
name: c975l-payment-in-app
description: "Use this skill when a site's mobile app sells through Apple's App Store or Google Play — plugging what the site sells in, verifying a purchase sent by the app, restoring purchases, taking back a refund, writing the store products. Triggers on: in-app purchase, IAP, App Store, Google Play, StoreKit, expo-iap, react-native-iap, InAppStoreInterface, InAppProductProviderInterface, InAppProduct, InAppTransaction, InAppPurchaseService, InAppPurchaseServiceInterface, accountToken, appAccountToken, obfuscatedAccountId, verifyPurchase, restore purchases, refund reversed, REFUND_REVERSED, StorePurchase, payment_store_purchase, StorePurchaseCrudController, AppleStore, GooglePlayStore, AppleJwsVerifier, AppleCatalogWriter, App Store Server API, App Store Server Notifications, Pub/Sub, voidedPurchaseNotification, store:publish, product id, non-consumable, payment_in_app_stores, InAppStoreExtension, terms of sale, privacy policy, legal_var."
---

# c975L PaymentBundle — selling in a mobile app

> The store is the merchant of record: no basket, no invoice, no VAT on this side. The bundle verifies, records and takes back; the site says what it sells and what a purchase gives.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Contract/InAppStoreInterface.php`, `src/Contract/InAppProductProviderInterface.php`, `src/Contract/InAppProduct.php`, `src/Contract/InAppTransaction.php`, `src/Service/InAppPurchaseService.php`, `src/Service/InAppCatalogPublisher.php`, `src/Store/AppleStore.php`, `src/Store/AppleCatalogWriter.php`, `src/Store/AppleJwsVerifier.php`, `src/Store/GooglePlayStore.php`, `src/Controller/StoreNotificationController.php`, `src/Twig/InAppStoreExtension.php`, `src/Controller/Management/StorePurchaseCrudController.php`, `src/Entity/StorePurchase.php`

**Related skills:** `c975l-payment-items` (the basket's equivalent) and `c975l-payment-gateway` in this same bundle.

## The site's side

One service implementing `InAppProductProviderInterface` — autoconfigured, no tagging:

| Method | Role |
| --- | --- |
| `getProducts()` | `InAppProduct` objects: id, price in cents for France, names and descriptions per locale, review screenshot path |
| `supports($productId)` | whether this provider sells it |
| `deliver($holder, $productId, $store)` | gives what was bought — **called again on a restore, a retry or a refund reversed, so it must be harmless** |
| `revoke($holder, $productId, $store)` | takes back what a refund cancelled |

`$holder` is whatever the site calls the owner (a user id, a device code), **191 characters at most**.

Product ids are derived, never typed: `InAppProduct::idFor('back', $slug)`. Both stores refuse a hyphen, and Apple never gives an id back once used — renaming the slug orphans the store product.

## Buying, from the site's own app endpoints

The bundle ships no endpoint for the app: only the site knows who its holder is. The site's controller:

1. returns `accountToken($holder)` — the app **must** buy under it (`appAccountToken` at Apple, `obfuscatedAccountId` at Google), or `verify()` refuses the purchase;
2. passes the app's proof to `verify($store, $holder, $proof)`: `{"transactionId"}` for Apple, `{"productId", "purchaseToken"}` for Google. `true` once delivered, `false` while pending or once refunded, `InvalidNotificationException` for a forged proof or another holder's, `PaymentUnavailableException` for a store without keys or that cannot be reached (keys refused, network, 5xx).

The purchase is recorded before `deliver()` runs, so a failed delivery or acknowledgement is retried by the next call; a concurrent call for the same transaction answers `true` without delivering twice. A refunded row the store calls paid again is reopened and delivered.

Never deliver on what the phone says it bought: only `verify()` asks the store itself. Apple is asked in production, then in the sandbox, because Apple's review buys in the sandbox from the production app — `payment-test-mode` plays no part here.

## Refunds

`POST /payment/store/{store}` (route `payment_store_notification`): App Store Server Notifications version 2 for Apple, a Pub/Sub push subscription for Google with `?token=` set to `google-play-notification-token`. Apple's payloads are JWS checked down to Apple Root CA - G3 (`config/certificates`). A refund calls `revoke()`; Apple's `REFUND_REVERSED` calls `deliver()` again. Google purchases are recorded under `token.<sha1(purchaseToken)>`, the key both the purchase and its voided notification carry.

The back office lists the recorded purchases read-only (*Payment › In-app purchases*), and the guided project `payment-in-app-setup` walks through the keys.

## Settings and catalogue

`apple-bundle-id`, `apple-issuer-id`, `apple-iap-key-id`, `apple-iap-private-key` verify at Apple (a key of type *In-App Purchase*); `apple-app-id`, `apple-key-id`, `apple-private-key` write the catalogue (the team key); `google-play-package-name` and `google-play-service-account` do both at Google. All in `config/configs.json`, never in `.env`.

`php bin/console c975l:payment:store:publish` writes every product into every store whose keys allow it — never create a product by hand in a store console. Apple's price is the French price point matching the price exactly.

## Legal texts

Once a store is fully set up, UiBundle's `france/terms-of-sales` model gains the `in-app-purchases` section and its `france/privacy-policy` the `third-parties.app-stores` unit, from `templates/legal/`. Both are keyed on the Twig function `payment_in_app_stores()` — the slugs of the stores whose `isConfigured()` holds, the same test the purchase endpoint makes — never on a single config value.

## Do not

- Deliver from the app's own claim: only `verify()` asks the store.
- Write `StorePurchase` rows yourself, or delete them: a refund finds its holder through them.
- Make `deliver()` fail on what is already held — it runs again on a restore, a retry and a refund reversed.
- Key a template on `config('apple-bundle-id')` or `config('google-play-package-name')`: an identifier alone is half a setup, ask `payment_in_app_stores()`.
