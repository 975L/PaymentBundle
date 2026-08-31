---
name: c975l-payment-items
description: "Use this skill when plugging a new kind of sellable item into the c975L basket from a satellite bundle — products, crowdfunding counterparts, services, files. Covers the provider contract, the pre/post-payment hooks and the one mistake that loses a customer's data. Triggers on: BasketItemProviderInterface, BasketItemProviderRegistry, onBasketValidated, onBasketPaid, validateCheckout, validateAddition, toBasketData, getContentFlags, getKind, checkout_data, BasketNotOrderableException, BasketRecommendationProviderInterface, BasketDownloadProviderInterface, BasketDownloadRegistry, getDownloads, expiresAt, hasPaidFor, holdsItem, paywall, createInlineResponse, WeighableBasketItemProviderInterface, getWeight, shipping weight, grams, ShippingZone, ShippingRate, CatalogueBasketItemProviderInterface, getCatalogueUrl, payment_catalogue_url, continue shopping, ContinueShoppingButton, getTemplate, recommendations template."
---

# c975L PaymentBundle — plugging sellable items in

> This bundle never learns what a product is. Every kind of sellable thing is contributed by the bundle that owns it, through one interface.

**Package:** `c975l/payment-bundle` · **Bundle:** `c975L\PaymentBundle\`

**Key source paths** (relative to the package root):
`src/Contract/BasketItemProviderInterface.php`, `src/Contract/BasketRecommendationProviderInterface.php`, `src/Contract/BasketDownloadProviderInterface.php`, `src/Registry/BasketItemProviderRegistry.php`, `src/Registry/BasketRecommendationRegistry.php`, `src/Registry/BasketDownloadRegistry.php`, `src/Repository/BasketRepository.php`, `src/Exception/BasketNotOrderableException.php`, `src/Contract/WeighableBasketItemProviderInterface.php`, `src/Contract/CatalogueBasketItemProviderInterface.php`, `src/Twig/CatalogueExtension.php`

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

## Saying what a line weighs

A provider selling something that is posted also implements `Contract\WeighableBasketItemProviderInterface`, whose single `getWeight(array $itemData): ?int` answers the weight of **one line, in grams, quantity included** — three articles of 400 g weigh 1200. Grams and whole, as prices are held in cents.

**Kept apart from `BasketItemProviderInterface` on purpose**, like the optional gateway interfaces: a provider selling nothing that ships stays valid without it, and so does one whose catalogue is not weighed yet. Nothing breaks on upgrade.

Answer `null` for a line that contributes nothing to weigh — a download, a service, a gift card sent by e-mail — and `null` again for an article carrying no weight. The caller adds `null` up **as nothing rather than as zero**: a half-weighed catalogue would otherwise price a parcel as if the rest of it were feathers.

Read the entry **with defaults rather than as a guaranteed shape**. An order's items are a snapshot frozen the day it was placed, and one taken before this bundle weighed anything carries no such key at all — a years-old order still has to be displayed, e-mailed and reprinted.

What the interface settles is where the fact lives: the weight belongs to the bundle that sells the article, the tariff grid and the zones to the checkout that posts the parcel (see `c975l-payment-checkout`).

## Saying where the catalogue is

The basket's "continue shopping" button goes back to a listing this bundle knows nothing about, so a provider selling out of one also implements `Contract\CatalogueBasketItemProviderInterface`, whose single `getCatalogueUrl(): ?string` answers its address — **a path you generate yourself**, being the only one to know the parameters and the fragment your own listing takes, never a route name for this bundle to resolve.

**Kept apart from `BasketItemProviderInterface` on purpose**, like the weight above: a provider selling a one-off payment link has no listing to return to. Nothing breaks on upgrade.

Answer `null` when the catalogue is not reachable for now — nothing on sale, the listing behind a closed shop. `BasketItemProviderRegistry::getCatalogueUrl()` takes the **first provider answering an address**, passing over the ones answering `null`, and `Twig\CatalogueExtension` exposes it as `payment_catalogue_url()`, what `Basket:ContinueShoppingButton` is drawn from. With nothing installed selling out of a catalogue the button is simply not drawn, rather than pointing at a route no site declares.

## The two optional registries

- **`BasketRecommendationProviderInterface`** — the cross-sell strip under the basket. `getRecommendations(Basket $basket, int $limit)` answers **your own entities**, and `getTemplate(): string` names the template drawing them, included with those entries as a `recommendations` variable **and nothing else** — no page context, so a `title` of your own is yours to set. The markup belongs to whichever bundle recommends; this one only says where the strip goes on the page. **Only one provider is asked**: the first registered wins for both, the others are never called. With none installed the strip is left out.
- **`BasketDownloadProviderInterface`** — `getDownloads(Basket $basket): array` of `{title, url, size, expiresAt}`, letting a buyer download again what they bought from their customer area. Unlike recommendations, **every** provider is asked and their answers concatenated — a basket can hold files of several kinds. Return `[]` for a basket holding nothing of yours. With nothing installed the section is left out of the page rather than drawn empty. It is called for a basket already checked as paid and as belonging to the user asking.

  **Hand over the links the delivery already made; never mint one here.** The page is read again long after the order, and a link minted on the visit would outlive what the buyer was promised — `expiresAt` is that promise, and it is what the page tells them.

## Gating a media on a purchase

**Not the same thing as `BasketDownloadProviderInterface` above, however much they look alike.** That one hands a bought **file** over to be downloaded, for as long as its emailed link lives. This one answers whether a **media** may be shown in the page at all — a paywalled photo, video or chapter.

`BasketRepository::hasPaidFor()` takes the buyer (a `UserInterface` matched on the account, a string matched on the address), your `getKind()` and the id the item was added under, and says whether one of their paid or shipped orders holds it. It says the purchase happened; it says nothing about a delay and never expires on its own. No entity to add, no right to keep beside the orders.

The paywall itself is yours, never this bundle's. Serve the file from outside `public/` with UiBundle's `PrivateFileResponseFactory::createInlineResponse()` — inline disposition, private response, and `Range` requests left to `BinaryFileResponse`, without which a video plays from its start and cannot be moved through. Never write the real path in the HTML, `src` attributes included. The teaser is yours too: the blurred thumbnail, the first seconds, and the button putting the media in the basket.

A page gating a whole gallery asks once per media, so keep the answer for the request rather than calling it in a loop over a hundred thumbnails.

## Do not

- **Do not claim a kind another installed bundle already claims** — the registry keys on it and the last one silently wins.
- **Do not read the session or the current request in `onBasketPaid()`.**
- **Do not store pre-payment data anywhere but the return value of `onBasketValidated()`.**
- **Do not keep a customer's details in `Basket::$checkoutData` as a record** — it is dropped on delivery, by design.
- **Do not write anything in `validateCheckout()`** — it runs before the order exists.
- **Do not mint a download link in `getDownloads()`** — hand over what the delivery made, with its `expiresAt`.
- **Do not keep a right of your own beside the orders for a paywall** — `hasPaidFor()` reads them, and two records of the same purchase end up disagreeing.
- **Do not add `getCatalogueUrl()` to `BasketItemProviderInterface`** — it is optional, and a provider selling a one-off payment link must stay valid without it.
- **Do not answer an address from `getCatalogueUrl()` for a catalogue nobody can browse** — `null` is the answer, and the button is then not drawn.
- **Do not draw the recommendation strip's heading in the basket page** — `getTemplate()` names your markup, headings included, and it is included without the page's context.
- **Do not add `getWeight()` to `BasketItemProviderInterface`** — it is optional, and a provider selling nothing that ships must stay valid without it.
- **Do not answer `0` from `getWeight()` for an article you have not weighed** — `null` is the answer, and it is added up as nothing.
- **Do not make this bundle aware of your entity.** It hands you a basket and renders what comes back.
- **Do not expect `onBasketPaid()` more than once** — nor fewer than once.
