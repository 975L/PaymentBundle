<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// Implemented, on top of BasketItemProviderInterface, by a provider whose items land on an account rather than at an address - credits, a subscription. Kept apart on purpose, like CatalogueBasketItemProviderInterface: the visitor fills the basket freely and is asked to sign in once, at the checkout, rather than before choosing anything. A marker only: validateCheckout() stays the provider's own guard
interface AccountBasketItemProviderInterface
{
}
