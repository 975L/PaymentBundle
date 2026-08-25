<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\UiBundle\Contract\GalleryShowcaseProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// Shows "payment_shipping", this bundle's only block kind, in a block showcase (see UiBundle's GalleryShowcaseRegistry). It doesn't fit BlockFixtureProviderInterface: its whole content is the delivery rate read from the configuration, and a site running a showcase sells nothing, so that rate is unset and the block renders nothing at all. Rendered here instead, directly against the same component, with sample amounts handed over as props
class GalleryShowcaseProvider implements GalleryShowcaseProviderInterface
{
    private const string TEMPLATE = '@c975LPayment/components/Basket/Shipping.html.twig';

    // In cents, as every amount of this bundle
    private const int FREE_FROM = 5000;
    private const int FLAT_RATE = 490;

    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Stands in for its own kind, so the showcase suppresses that kind's own empty preview card. One variant per sentence the component can say, those three being exactly what the block offers a page
    public function getShowcases(): array
    {
        return [
            $this->translator->trans('label.block_shipping', [], 'payment') => [
                'description' => $this->translator->trans('label.block_shipping_description', [], 'payment'),
                'kind' => 'payment_shipping',
                'variants' => [
                    'Seuil de gratuité' => $this->render(['free' => self::FREE_FROM]),
                    'Forfait' => $this->render(['shipping' => self::FLAT_RATE, 'free' => 0]),
                    'Livraison offerte' => $this->render(['shipping' => 0, 'free' => 0]),
                ],
            ],
        ];
    }

    // The currency goes with the amounts, being read from the same unset configuration
    /**
     * @param array<string, int> $amounts
     */
    private function render(array $amounts): string
    {
        return $this->twig->render(self::TEMPLATE, $amounts + ['currency' => 'EUR']);
    }
}
