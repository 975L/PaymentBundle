/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";
import Handlers from "./handlers.js";

// Rubs the panel off the verso of a card, i.e. asks the server for the code it hides. The code is never in the page before that: a card's address is meant to be forwarded, and a link pasted into a chat is fetched by a robot that reads the markup and runs no script
export default class extends Controller {
    static targets = ["code", "scratch"];
    static values = { url: String };

    async reveal() {
        // A second press asks for nothing: the code is already on the card
        if (this.revealed) {
            return;
        }
        this.revealed = true;

        try {
            const response = await fetch(this.urlValue, {
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            const data = await response.json();

            if (!response.ok || !data.code) {
                throw new Error(data.error || "unavailable");
            }

            this.codeTarget.textContent = data.code;
            this.codeTarget.hidden = false;
            this.element.classList.add("gift-card-scratched");
        } catch (error) {
            // The panel stays, so the card can be rubbed again: what failed is a request, and a card left with neither a code nor a panel says nothing at all
            this.revealed = false;
            if (this.hasScratchTarget) {
                this.scratchTarget.textContent = Handlers.translate("gift_card.reveal.error");
            }
        }
    }
}
