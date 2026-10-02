/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Hands a paid order to Matomo's queue, which applies the site id and the tracker url first whatever the order things were pushed in. Matomo counts an order number once, so a reload or a Turbo restore records nothing twice
export default class extends Controller {
    connect() {
        const order = JSON.parse(this.element.dataset.matomoOrder);
        window._paq = window._paq || [];

        for (const item of order.items) {
            window._paq.push(["addEcommerceItem", item.sku, item.name, item.category, item.price, item.quantity]);
        }
        window._paq.push(["trackEcommerceOrder", order.id, order.grandTotal, order.subTotal, order.tax, order.shipping, order.discount]);
    }
}
