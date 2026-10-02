<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\Group;

// assets/js/matomo-order.js run over what Basket/MatomoOrder.html.twig draws: Matomo files items under the order tracked right after them, so a line pushed after trackEcommerceOrder is simply lost
#[Group('browser')]
class MatomoOrderBehaviourTest extends JsCase
{
    public function testTheItemsThenTheOrderAreQueued(): void
    {
        $order = [
            'id' => '20261002-123456',
            'grandTotal' => 101.9,
            'subTotal' => 100,
            'tax' => 16.67,
            'shipping' => 6.9,
            'discount' => 5,
            'items' => [['sku' => 'product-12', 'name' => 'Book (Hardcover)', 'category' => 'product', 'price' => 30, 'quantity' => 2]],
        ];

        $queue = $this->observe(
            \sprintf('<div data-controller="matomoOrder" data-matomo-order="%s" hidden></div>', htmlspecialchars(json_encode($order, \JSON_THROW_ON_ERROR))),
            ['matomoOrder' => 'matomo-order'],
            'return window._paq;',
            // A queue already holding the tracker's own calls, which have to be kept
            ['before' => 'window._paq = [["trackPageView"]];']
        );

        $this->assertSame([
            ['trackPageView'],
            ['addEcommerceItem', 'product-12', 'Book (Hardcover)', 'product', 30, 2],
            ['trackEcommerceOrder', '20261002-123456', 101.9, 100, 16.67, 6.9, 5],
        ], $queue);
    }
}
