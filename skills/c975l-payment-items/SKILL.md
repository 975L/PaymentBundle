---
name: c975l-payment-items
description: "Use this skill when plugging a new kind of sellable item into the c975L basket from a satellite bundle — products, crowdfunding counterparts, services, files. Covers the provider contract, the pre/post-payment hooks and the one mistake that loses a customer's data. Triggers on: BasketItemProviderInterface, BasketItemProviderRegistry, onBasketValidated, onBasketPaid, validateCheckout, validateAddition, toBasketData, getContentFlags, getKind, checkout_data, BasketNotOrderableException, BasketRecommendationProviderInterface, BasketDownloadProviderInterface, BasketDownloadRegistry."
---

# c975L PaymentBundle — plugging sellable items in

> This bundle never learns what a product is. Every kind of sellable thing is contributed by the bundle that owns it, through one interface.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Contract/BasketItemProviderInterface.php`, `src/Contract/BasketRecommendationProviderInterface.php`, `src/Contract/BasketDownloadProviderInterface.php`, `src/Registry/BasketItemProviderRegistry.php`, `src/Registry/BasketRecommendationRegistry.php`, `src/Registry/BasketDownloadRegistry.php`, `src/Exception/BasketNotOrderableException.php`

**Related skills:** `c975l-payment-checkout` and `c975l-payment-gateway` in this same bundle.

## The contract

Implement `Contract\BasketItemProviderInterface` in a service — autoconfigured, no manual tagging, collected by `BasketItemProviderRegistry` and keyed on `getKind()`. Reference implementations: `c975L\ShopBundle\Service\ProductBasketItemProvider` (kind `product`) and `c975L\CrowdfundingBundle\Service\CrowdfundingBasketItemProvider` (kind `crowdfunding`).

| Method | Role |
| --- | --- |
| `getKind()` | the kind a basket line names — **must be unique across installed bundles** |
| `findItem(int\|string $id)` | your entity, or null |
| `validateAddition(object $item, int $quantity)` | why it cannot be added, or null |
| `validateCheckout(Basket $basket, array $itemsOfThisKind)` | why it can no longer be ordered, or null |
| `toBasketData(object $item, int $quantity)` | the line as the basket stores it |
| `getContentFlags(array $itemData)` | this line's contribution to the basket's bitmask |
| `onBasketValidated(...)` | pre-payment hook — **returns what you will need later** |
| `onBasketPaid(...)` | post-payment hook — runs once, gets that data back |

`validateCheckout()` runs for every provider at the top of `validate()`, **before anything is numbered, charged or written**. Its message is shown as-is via `BasketNotOrderableException` — only the bundle owning the item can say what is wrong with it. Nothing has been written when it throws, so the basket the visitor comes back to is the one they left.

## The pair of hooks — the thing to get right

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

**Do not keep that data in the session, and do not read the current request in `onBasketPaid()`.**

`onBasketPaid()` is reached from the payment provider's **webhook** as well as from the customer's return, and the webhook is a request *from the provider*: it carries no session of that customer. Anything left in the session is lost for everyone who does not come back to the site before the webhook lands — which is routine on mobile and after a 3-D Secure step.

What you hand over is kept on `Basket.checkout_data`, keyed by kind, and **dropped as soon as the basket is delivered or its checkout called off**: it carries the customer's own details across the payment and is no record.

Most kinds have nothing to carry — what was ordered is already on the basket. Return `[]` and read `$itemsOfThisKind`. That is what `c975L\ShopBundle\Service\ProductBasketItemProvider` does.

## Content flags

`getContentFlags()` returns this line's bits, OR-ed into `Basket::$contentflags`:

`CONTENT_FLAG_DIGITAL` 1 · `CONTENT_FLAG_PHYSICAL` 2 · `CONTENT_FLAG_CF_SHIPPING` 4 · `CONTENT_FLAG_CF_DIGITAL` 8 · `CONTENT_FLAG_SERVICE` 16

They decide whether the order needs shipping, whether it appears in the "orders to ship" figure, and what the order page renders. Getting them wrong makes a digital-only order wait forever for a shipment.

## The two optional registries

- **`BasketRecommendationProviderInterface`** — the cross-sell strip under the basket. **Only one provider is asked**: the first registered wins, the others are never called. `[]` with none installed.
- **`BasketDownloadProviderInterface`** — `getDownloads(Basket $basket): array` of `{title, url, size}`, letting a buyer download again what they bought however long ago the emailed link expired. Unlike recommendations, **every** provider is asked and their answers concatenated — a basket can hold files of several kinds. Return `[]` for a basket holding nothing of yours. With nothing installed the section is left out of the page rather than drawn empty. It is called for a basket already checked as paid and as belonging to the user asking.

## Do not

- **Do not claim a kind another installed bundle already claims** — the registry keys on it and the last one silently wins.
- **Do not read the session or the current request in `onBasketPaid()`.**
- **Do not store pre-payment data anywhere but the return value of `onBasketValidated()`.**
- **Do not keep a customer's details in `Basket::$checkoutData` as a record** — it is dropped on delivery, by design.
- **Do not write anything in `validateCheckout()`** — it runs before the order exists.
- **Do not make this bundle aware of your entity.** It hands you a basket and renders what comes back.
- **Do not expect `onBasketPaid()` more than once** — nor fewer than once.
