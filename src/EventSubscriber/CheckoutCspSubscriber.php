<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\EventSubscriber;

use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

// Names every offered provider's checkout in "form-action", checked over the whole redirection chain, so a policy limited to 'self' takes the order then loses the customer on the way to paying; read from the config, it follows the back-office
class CheckoutCspSubscriber implements EventSubscriberInterface
{
    // The enforced policy and the reported one, each under the name NelmioSecurityBundle also emits for older browsers. A site setting its policy some other way is served the same way, and one setting none at all is left alone
    private const array HEADERS = [
        'Content-Security-Policy',
        'Content-Security-Policy-Report-Only',
        'X-Content-Security-Policy',
        'X-Content-Security-Policy-Report-Only',
    ];

    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Behind everything that writes a policy: NelmioSecurityBundle's own listener sits at 0, and the WebProfiler rebuilds the header from its directives at -128
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1000]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (!$this->hasPolicy($response)) {
            return;
        }

        // Every provider offered and not only the default one: the customer picks at the basket, and a host left out of the policy takes the order then loses them on the way to paying. A shop offering none has no checkout to authorize
        $domains = [];
        foreach ($this->gatewayRegistry->getOffered() as $gateway) {
            $domains = array_merge($domains, $gateway->getCheckoutDomains());
        }
        $domains = array_unique($domains);
        if ([] === $domains) {
            return;
        }

        foreach (self::HEADERS as $header) {
            if (!$response->headers->has($header)) {
                continue;
            }

            $values = [];
            foreach ($response->headers->all($header) as $value) {
                $values[] = null === $value ? $value : $this->addDomains($value, $domains);
            }

            $response->headers->set($header, $values);
        }
    }

    // Whether the response carries a policy at all, asked before the registry so a site without any CSP never pays for a config lookup on every response
    private function hasPolicy(Response $response): bool
    {
        return array_any(self::HEADERS, fn ($header) => $response->headers->has($header));
    }

    /**
     * Adds the domains to the policy's "form-action", leaving the rest of it untouched.
     *
     * A policy not declaring that directive is returned as it stands: "form-action" falls back on nothing - not even
     * "default-src" - so a site that never wrote it restricts no form submission, and there is nothing to widen.
     *
     * @param string[] $domains
     */
    private function addDomains(string $policy, array $domains): string
    {
        $updated = preg_replace_callback(
            '/(^|;)\s*form-action((?:\s+[^;\s]+)*)/i',
            static function (array $matches) use ($domains): string {
                $sources = preg_split('/\s+/', trim($matches[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                // 'none' is only valid on its own, so a site forbidding every form has it dropped rather than being served a policy the browser throws away whole - which would lift the restriction on every other form of the site
                $sources = array_values(array_filter($sources, static fn (string $source): bool => "'none'" !== strtolower($source)));

                foreach ($domains as $domain) {
                    if (!in_array($domain, $sources, true)) {
                        $sources[] = $domain;
                    }
                }

                return ('' === $matches[1] ? '' : $matches[1] . ' ') . 'form-action ' . implode(' ', $sources);
            },
            $policy,
            1
        );

        return $updated ?? $policy;
    }
}
