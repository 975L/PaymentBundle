<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\EventSubscriber;

use c975L\ConfigBundle\Service\CspFormAction;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

// Names every offered provider's checkout in "form-action", checked over the whole redirection chain, so a policy limited to 'self' takes the order then loses the customer on the way to paying; read from the config, it follows the back-office
class CheckoutCspSubscriber
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {
    }

    // Behind everything that writes a policy: NelmioSecurityBundle's own listener sits at 0, and the WebProfiler rebuilds the header from its directives at -128
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !CspFormAction::hasPolicy($response)) {
            return;
        }

        // Every provider offered and not only the default one: the customer picks at the basket, and a host left out of the policy takes the order then loses them on the way to paying. A shop offering none has no checkout to authorize
        $domains = [];
        foreach ($this->gatewayRegistry->getOffered() as $gateway) {
            $domains = array_merge($domains, $gateway->getCheckoutDomains());
        }

        CspFormAction::widen($response, array_values(array_unique($domains)));
    }
}
