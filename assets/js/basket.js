/*
 * (c) 2020: 975L <contact@975l.com>
 * (c) 2020: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";
import Handlers from "./handlers.js";

// Shared by every instance on the page - a product sheet carries one controller of its own and one per block of items, and they would otherwise each fetch the same basket
let basketDataPromise = null;
let lastFetchTime = 0;
let timezoneSent = false;
const CACHE_DURATION = 5000;

export default class extends Controller {
    static targets = ["quantity", "subtotal", "total", "vat", "vatRow", "shipping", "freeShipping", "submitButton", "itemTotal", "itemQuantity", "code", "codeRow", "codeAmount", "codeLabel"];

    connect() {
        // Sets timezone in Symfony session, once for the browsing session however many controllers the pages carry
        if (!timezoneSent) {
            timezoneSent = true;
            Handlers.sendTimezoneToServer();
        }

        // The add buttons live outside this controller's element as often as inside it, so the update travels through the document rather than through the DOM tree
        this.onGlobalUpdate = this.handleGlobalUpdate.bind(this);
        document.addEventListener("basket:update", this.onGlobalUpdate);

        this.loadBasketData().then((data) => this.update(data));
    }

    // Turbo caches the page and connects the controller again on the way back, so a listener left behind would pile up one copy per visit
    disconnect() {
        document.removeEventListener("basket:update", this.onGlobalUpdate);
    }

    // Loads the basket, one fetch at a time for the whole document
    loadBasketData() {
        const now = Date.now();

        if (!basketDataPromise || now - lastFetchTime > CACHE_DURATION) {
            lastFetchTime = now;
            basketDataPromise = fetch("/shop/basket/json")
                .then((response) => response.ok ? response.json() : Promise.reject(response.status))
                .catch(() => {
                    Handlers.displayMessage(Handlers.translate("basket.load.error"), "alert-danger");
                    // Dropped so the next connect retries rather than handing out the failure again
                    basketDataPromise = null;

                    return null;
                });
        }

        return basketDataPromise;
    }

    // Empties the basket
    delete() {
        fetch("/shop/basket", { method: "DELETE" })
            .then((response) => response.ok ? response : Promise.reject(response.status))
            .then(() => {
                basketDataPromise = null;
                window.location.reload();
            })
            .catch(() => Handlers.displayMessage(Handlers.translate("basket.delete.error"), "alert-danger"));
    }

    // Adds a quantity of item to the basket
    addItem(event) {
        this.animation(event.currentTarget);
        this.send(event.currentTarget, "/shop/basket", "POST", event.currentTarget.dataset.quantity, "basket.add.error");
    }

    // Removes a quantity of item from the basket
    removeItem(event) {
        this.animation(event.currentTarget);
        this.send(event.currentTarget, "/shop/basket", "POST", event.currentTarget.dataset.quantity, "basket.add.error");
    }

    // Deletes an item whatever its quantity
    deleteItem(event) {
        this.send(event.currentTarget, "/shop/basket/delete", "DELETE", 0, "product.delete.error");
    }

    // The three write the same body to the same shape of answer, only the route, the quantity and the message they fail with change
    send(target, url, method, quantity, errorKey) {
        fetch(url, {
            method: method,
            body: JSON.stringify({
                id: target.dataset.itemId,
                quantity: quantity,
                type: target.dataset.type,
            }),
            headers: {
                "Content-Type": "application/json",
            }
        })
            .then((response) => response.ok ? response.json() : Promise.reject(response.status))
            .then((data) => {
                // Dropped so the next read sees the basket as this call left it
                basketDataPromise = null;

                if (data.error) {
                    Handlers.displayMessage(data.error, "alert-danger");

                    return;
                }

                Handlers.displayMessage(`"${target.dataset.title}" ${target.dataset.text}`, `alert-${target.dataset.alert}`);
                this.update(data);
            })
            .catch(() => Handlers.displayMessage(Handlers.translate(errorKey), "alert-danger"));
    }

    // What is actually charged: the items, the shipping, less whatever a code took off. The server holds the same rule (see Basket::getPayable()) - this is what the page has to print beside it
    payable(basket) {
        return Math.max(0, basket.total + basket.shipping - (basket.discountAmount ?? 0));
    }

    // Sends the basket's one code, the server telling a promotion from a gift card, and answers whether it was taken - what the validate button waits for
    applyCode() {
        if (!this.hasCodeTarget || "" === this.codeTarget.value.trim()) {
            return Promise.resolve(false);
        }

        return this.sendCode("POST", { code: this.codeTarget.value.trim() }, "basket.code.error");
    }

    // A code typed and left in the field is applied on the way to the coordinates: a customer who did not see the "Apply" button beside it would have paid full price
    validateWithCode(event) {
        const link = event.target.closest("a");

        if (null === link || !this.hasCodeTarget || "" === this.codeTarget.value.trim()) {
            return;
        }

        event.preventDefault();

        // A refused code keeps the customer on the basket, where the server's own sentence is displayed
        this.applyCode().then((applied) => {
            if (applied) {
                window.location.href = link.href;
            }
        });
    }

    removeCode() {
        this.sendCode("DELETE", {}, "basket.code.error");
    }

    sendCode(method, body, errorKey) {
        return fetch("/shop/basket/code", {
            method: method,
            body: JSON.stringify(body),
            headers: {
                "Content-Type": "application/json",
            }
        })
            .then((response) => response.ok ? response.json() : Promise.reject(response.status))
            .then((data) => {
                // Dropped so the next read sees the basket as this call left it
                basketDataPromise = null;

                // The refusal is the server's own sentence, already translated: only it knows whether the code is unknown, expired, out of quota or short of a minimum
                if (data.error) {
                    Handlers.displayMessage(data.error, "alert-danger");

                    return false;
                }

                if (this.hasCodeTarget) {
                    this.codeTarget.value = "";
                }

                this.update(data);

                return true;
            })
            .catch(() => {
                Handlers.displayMessage(Handlers.translate(errorKey), "alert-danger");

                return false;
            });
    }

    // Shows or hides the line a code adds to the totals, and says which of the two kinds it is
    updateCodeDisplay(data) {
        if (!this.hasCodeRowTarget || !data.basket) {
            return;
        }

        const amount = data.basket.discountAmount ?? 0;
        this.codeRowTarget.hidden = amount <= 0;

        if (amount <= 0) {
            return;
        }

        if (this.hasCodeLabelTarget) {
            // Each key written out in full: the translations are checked against what this file literally asks for
            const kind = "gift_card" === data.basket.discountKind ? Handlers.translate("basket.gift_card") : Handlers.translate("basket.discount");
            this.codeLabelTarget.textContent = `${kind} ${data.basket.discountCode}`;
        }

        if (this.hasCodeAmountTarget) {
            this.codeAmountTarget.textContent = `-${Handlers.formatAmount(amount, data.basket.currency)}`;
        }
    }

    // Updates what this controller holds, then tells the other instances of the page
    update(data) {
        if (!data) {
            return;
        }

        this.updateBasketButton(data);
        this.updateBasketPage(data);

        // Outside updateBasketPage(), which returns as soon as the document is not the basket: a product sheet carries add buttons and no basket table, and its digital items would stay clickable for a file already in the basket
        this.updateAddButtons(data);

        // Prefixed with the identifier by Stimulus, so the event name stays "basket:update"
        this.dispatch("update", { detail: { data } });
    }

    // Updates the basket navbar
    updateBasketButton(data) {
        this.updateBasketNavbarDisplay(data);
        this.updateBasketCounters(data);
    }

    // Updates the count and the total the basket button carries
    updateBasketCounters(data) {
        if (!data.basket) {
            return;
        }

        if (this.hasTotalTarget) {
            this.totalTarget.textContent = Handlers.formatAmount(this.payable(data.basket), data.basket.currency);
        }

        if (this.hasQuantityTarget) {
            this.quantityTarget.textContent = data.basket.quantity;
        }
    }

    // Updates the basket page
    updateBasketPage(data) {
        const basketPage = document.getElementById("basket-page");
        if (!basketPage || !data.basket) {
            return;
        }

        // Display empty basket template if quantity is 0
        if (0 === data.basket.quantity) {
            const template = document.getElementById("empty-basket-template");
            if (template) {
                basketPage.innerHTML = "";
                basketPage.appendChild(template.content.cloneNode(true));
            }

            return;
        }

        this.removeDeletedItems(data);
        this.updateExistingItems(data);
        this.updateBasketTotals(data);
        this.updateSubmitButton(data);
    }

    // Removes the rows of the items the basket no longer holds
    removeDeletedItems(data) {
        if (!data.basket?.items) {
            return;
        }

        const kept = Object.entries(data.basket.items).flatMap(
            ([type, items]) => Object.keys(items ?? {}).map((id) => `${type}-${id}`)
        );

        document.querySelectorAll("tr[id^=\"item-\"]").forEach((row) => {
            if (!kept.includes(`${row.dataset.type}-${row.dataset.itemId}`)) {
                row.classList.add("fade-out");
                setTimeout(() => row.remove(), 100);
            }
        });
    }

    // Updates existing items
    updateExistingItems(data) {
        if (!data.basket?.items) {
            return;
        }

        Object.entries(data.basket.items).forEach(([type, items]) => {
            Object.entries(items ?? {}).forEach(([id, itemData]) => { this.updateItemRow(`${type}-${id}`, itemData); });
        });
    }

    // Updates the basket totals
    updateBasketTotals(data) {
        this.updateBasketCounters(data);
        this.updateSubtotalDisplay(data);
        this.updateShippingDisplay(data);
        this.updateFreeShippingDisplay(data);
        this.updateVatDisplay(data);
        this.updateCodeDisplay(data);
    }

    // What the articles alone are worth, before the shipping and before a code
    updateSubtotalDisplay(data) {
        if (!this.hasSubtotalTarget || !data.basket) {
            return;
        }

        this.subtotalTarget.textContent = Handlers.formatAmount(data.basket.total, data.basket.currency);
    }

    // The tax held in what is paid, one line whatever the rates - the recap per rate is stated on the order, once nothing can move any more (see components/Basket/Items.html.twig)
    updateVatDisplay(data) {
        if (!this.hasVatRowTarget || !data.basket) {
            return;
        }

        const amount = data.basket.vat ?? 0;

        this.vatRowTarget.hidden = 0 === amount;

        if (this.hasVatTarget) {
            this.vatTarget.textContent = Handlers.formatAmount(amount, data.basket.currency);
        }
    }

    // Says what is left to reach the free shipping, the line the basket page raises an order with (see components/Basket/Shipping.html.twig)
    updateFreeShippingDisplay(data) {
        if (!this.hasFreeShippingTarget || !data.basket) {
            return;
        }

        const line = this.freeShippingTarget;
        const free = parseInt(line.dataset.shippingFree, 10) || 0;
        const flag = parseInt(line.dataset.shippingFlag, 10) || 0;

        // Nothing to reach without a threshold, and nothing to ship once the basket only holds files or services
        if (0 === free || 0 === (data.basket.contentflags & flag)) {
            line.hidden = true;

            return;
        }

        // Compared against what the basket holds and not against what is paid, the way the server applies the shipping (see BasketService::updateTotals())
        const missing = free - data.basket.total;
        const amount = Handlers.formatAmount(missing, data.basket.currency);

        line.hidden = false;
        line.textContent = missing > 0
            ? Handlers.translate("basket.free_shipping_missing").replace("%amount%", amount)
            : Handlers.translate("basket.free_shipping_reached");
    }

    // Updates the shipping display
    updateShippingDisplay(data) {
        if (!this.hasShippingTarget || !data.basket) {
            return;
        }

        this.shippingTarget.textContent = data.basket.shipping > 0
            ? Handlers.formatAmount(data.basket.shipping, data.basket.currency)
            : Handlers.translate("basket.offered");
    }

    // Updates the submit button
    updateSubmitButton(data) {
        if (!this.hasSubmitButtonTarget || !data.basket) {
            return;
        }

        const total = Handlers.formatAmount(this.payable(data.basket), data.basket.currency);

        this.submitButtonTarget.value = `${Handlers.translate("label.pay")} ${total}`;
    }

    // Updates a single row of the basket page, the targets being keyed on "type-id"
    updateItemRow(combinedId, itemData) {
        const itemQuantityElement = this.itemQuantityTargets.find((target) => target.dataset.itemId === combinedId);
        if (itemQuantityElement) {
            itemQuantityElement.textContent = itemData.quantity;
        }

        const itemTotalElement = this.itemTotalTargets.find((target) => target.dataset.itemId === combinedId);
        if (itemTotalElement) {
            itemTotalElement.textContent = 0 === itemData.total ? Handlers.translate("label.free") : Handlers.formatAmount(itemData.total, itemData.item.currency);
        }
    }

    // Disables the add buttons there is nothing left to add to
    updateAddButtons(data) {
        document.querySelectorAll("[data-action='click->basket#addItem']").forEach((button) => {
            const basketItem = data.basket?.items?.[button.dataset.type]?.[button.dataset.itemId];
            const inBasket = basketItem?.quantity ?? 0;

            // A digital item is bought once: a second copy of the same file is nothing to sell
            if (inBasket > 0 && basketItem.item?.file) {
                button.setAttribute("disabled", "disabled");
            }

            // Nothing left to order once what is already ordered plus what the basket holds reaches the limit
            const limited = parseInt(button.dataset.limited, 10);
            const ordered = parseInt(button.dataset.ordered, 10) || 0;
            if (limited > 0 && ordered + inBasket >= limited) {
                button.setAttribute("disabled", "disabled");
                button.classList.add("disabled");
            }
        });
    }

    // Shows the basket navbar as soon as the basket holds something
    updateBasketNavbarDisplay(data) {
        const basketNavbar = document.getElementById("basket-navbar");
        if (!basketNavbar) {
            return;
        }

        const isEmpty = !data.basket || 0 === data.basket.quantity;
        basketNavbar.classList.toggle("d-none", isEmpty);
        document.body.classList.toggle("has-basket-navbar", !isEmpty);
    }

    // Handles the updates the other instances of the page dispatch, the one that dispatched having updated itself already
    handleGlobalUpdate(event) {
        if (!event.detail?.data || event.target === this.element) {
            return;
        }

        basketDataPromise = null;
        this.updateBasketButton(event.detail.data);
        this.updateAddButtons(event.detail.data);
    }

    // Adds an animation to the clicked button
    animation(clickedButton) {
        if (!clickedButton.classList.contains("btn-primary")) {
            return;
        }

        clickedButton.classList.remove("btn-primary");
        clickedButton.classList.add("btn-secondary", "zoom-out-animation");
        setTimeout(() => {
            clickedButton.classList.remove("zoom-out-animation", "btn-secondary");
            clickedButton.classList.add("btn-primary");
        }, 500);
    }
}
