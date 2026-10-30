<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use Twig\Attribute\AsTwigFunction;

// What a page may say about selling in an app. An app identifier alone is half a setup, the store still lacking the keys to verify a purchase; a legal text asking through here announces in-app purchases only once the purchase endpoint can actually take them
class InAppStoreExtension
{
    public function __construct(private readonly InAppStoreRegistry $storeRegistry)
    {
    }

    // The slugs of the stores fully set up
    /** @return list<string> */
    #[AsTwigFunction('payment_in_app_stores')]
    public function stores(): array
    {
        return array_keys(array_filter($this->storeRegistry->all(), static fn (InAppStoreInterface $store): bool => $store->isConfigured()));
    }
}
