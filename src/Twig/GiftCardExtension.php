<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Service\GiftCardService;
use Twig\Attribute\AsTwigFunction;

// A Twig function rather than a column carried across the checkout: the cards an order bought are rows of their own, written by the very delivery that numbered the order (see GiftCardService::issue()), and the order's page and its email both read them back from there
class GiftCardExtension
{
    public function __construct(private readonly GiftCardService $giftCardService)
    {
    }

    /**
     * @return GiftCard[]
     */
    #[AsTwigFunction('payment_gift_cards')]
    public function giftCards(Basket $basket): array
    {
        return $this->giftCardService->findIssuedBy($basket);
    }
}
