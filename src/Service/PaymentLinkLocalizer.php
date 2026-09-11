<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\LocalizedUrlGenerator;
use c975L\UiBundle\Contract\InternalLinkLocalizerInterface;

// Rewrites this bundle's own links into the language the page around them is being read in - the counterpart of SiteBundle's PageLinkLocalizer, for the three screens a visitor navigates to on purpose. Without it a call to action stored on an English page sends a visitor into the writing language at the first click on "the basket"
class PaymentLinkLocalizer implements InternalLinkLocalizerInterface
{
    // The three screens this bundle answers both bare and localised, listed rather than matched on a prefix: the rest of "/shop/basket/..." is a checkout step or an endpoint, a token url is read in the order's own language and the invoice has no twin. A query string or an anchor travels with the link, a further segment does not - "/account/orders/{number}" is another screen
    private const array PATHS = [
        '#^/shop/basket/display(?<rest>[?\#].*)?$#' => 'basket_display',
        '#^/shop/basket/validate(?<rest>[?\#].*)?$#' => 'basket_validate',
        '#^/account/orders(?<rest>[?\#].*)?$#' => 'customer_orders',
    ];

    public function __construct(private readonly LocalizedUrlGenerator $localizedUrlGenerator)
    {
    }

    public function localize(string $value): string
    {
        // A whole rich text: only what an href holds is a link, the same words elsewhere in the prose being prose
        if (str_contains($value, 'href="')) {
            return (string) preg_replace_callback(
                '#href="([^"]*)"#',
                fn (array $matches): string => sprintf('href="%s"', $this->localizePath($matches[1])),
                $value
            );
        }

        return $this->localizePath($value);
    }

    // The generator holds the whole rule - the language being read, the twin, the fallback - so a stored link, a menu item and a template's own "localized_path" all read a link the same way
    private function localizePath(string $path): string
    {
        foreach (self::PATHS as $pattern => $route) {
            if (1 === preg_match($pattern, $path, $matches)) {
                return $this->localizedUrlGenerator->path($route) . ($matches['rest'] ?? '');
            }
        }

        return $path;
    }
}
