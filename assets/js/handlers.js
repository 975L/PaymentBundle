/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import UiHandlers from "@c975l/ui-bundle/handlers.js";
import translations from "./translations.js";

export default {
    translations: translations,

    // Both read "this.translations", so borrowing them here hands them this bundle's own catalogue rather than UiBundle's
    getLanguage: UiHandlers.getLanguage,
    translate: UiHandlers.translate,

    // Displays a message in the placeholder the Basket:Message component draws
    displayMessage(message, alertClass) {
        const messageElement = document.querySelector(".global-message");
        if (!messageElement) {
            return;
        }

        messageElement.className = `global-message alert ${alertClass}`;
        messageElement.textContent = message;
        messageElement.style.display = "block";
        messageElement.style.opacity = "1";
    },

    // Gets timezone from browser to be stored in Symfony session
    sendTimezoneToServer() {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        // Sends request
        fetch("/set-timezone", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify({ timezone })
        });

        return timezone;
    },

    // Formats an amount held in cents the way the page's language writes it - Intl carries the separator, the place of the symbol and the decimals of the currency, so a total rewritten here reads exactly as the one the server rendered with |format_currency
    formatAmount(amount, currencyCode) {
        const value = amount / 100;

        if (!currencyCode) {
            return value.toFixed(2);
        }

        return new Intl.NumberFormat(this.getLanguage(), { style: "currency", currency: currencyCode.toUpperCase() }).format(value);
    }
};
