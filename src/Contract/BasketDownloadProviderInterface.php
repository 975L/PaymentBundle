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

// Implemented by a bundle selling files, so a buyer downloads them again from their customer area - this bundle hands over a paid basket and renders whatever links come back. Tagged "payment.basket_download_provider" by autoconfiguration
interface BasketDownloadProviderInterface
{
    /**
     * The files of that basket this provider is responsible for, ready to be downloaded again.
     *
     * Called for a basket already checked as paid and as belonging to the user asking - a provider hands
     * links over without re-checking ownership, but must return [] for a basket holding nothing of its kind.
     * A provider hands over what its delivery already made and never mints a link here: this page is read
     * again long after the order, and a link minted on the visit would outlive what the buyer was promised.
     *
     * @return list<array{title: string, url: string, size: ?int, expiresAt: ?\DateTimeInterface}> "url" being ready to render as it is, "expiresAt" what the page tells the buyer
     */
    public function getDownloads(Basket $basket): array;
}
