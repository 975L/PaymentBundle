<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

use c975L\PaymentBundle\Entity\Basket;

// Implemented by a bundle selling files, so a buyer can download them again from their customer area rather than only from the emailed link that expires. Tagged "payment.basket_download_provider" by autoconfiguration
// This bundle never learns what a product is: it hands over a paid basket and renders whatever links come back
interface BasketDownloadProviderInterface
{
    /**
     * The files of that basket this provider is responsible for, ready to be downloaded again.
     *
     * Called for a basket already checked as paid and as belonging to the user asking - a provider mints
     * links without re-checking ownership, but must return [] for a basket holding nothing of its kind.
     *
     * @return list<array{title: string, url: string, size: ?int}> "url" being ready to render as it is
     */
    public function getDownloads(Basket $basket): array;
}
