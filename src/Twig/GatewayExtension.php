<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use Twig\Attribute\AsTwigFunction;

// What a page may say about how the shop is paid. The config alone used to answer it, one provider being named there; a customer choosing between those whose keys are filled in cannot be read off a single value, and a template asking the registry through here is asking the same question the checkout asks
class GatewayExtension
{
    public function __construct(private readonly PaymentGatewayRegistry $gatewayRegistry)
    {
    }

    /**
     * The slugs of the providers offered, the default one first.
     *
     * @return list<string>
     */
    #[AsTwigFunction('payment_gateways')]
    public function gateways(): array
    {
        return array_keys($this->gatewayRegistry->getOffered());
    }
}
