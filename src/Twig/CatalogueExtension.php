<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use Twig\Attribute\AsTwigFunction;

// Where the "continue shopping" button sends the customer, asked of whichever bundle sells out of a catalogue - this one holds baskets and knows of no shop of its own (see CatalogueBasketItemProviderInterface)
class CatalogueExtension
{
    public function __construct(private readonly BasketItemProviderRegistry $itemProviderRegistry)
    {
    }

    // Null when nothing installed has a listing to go back to: the button is then not drawn, rather than pointing at a route no site declares
    #[AsTwigFunction('payment_catalogue_url')]
    public function url(): ?string
    {
        return $this->itemProviderRegistry->getCatalogueUrl();
    }
}
