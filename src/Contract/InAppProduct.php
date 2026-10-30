<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

// One product a site sells in its app, as the stores are told about it: a one-off purchase kept for good (a "non-consumable"). $price is in cents of euros, the price set for France from which the stores derive the others; $names and $descriptions are keyed by the site's locale ("fr", "en", "es"); $screenshot is the absolute path of the picture Apple's review is shown, null for none
final readonly class InAppProduct
{
    // The store locale each site locale is published as, the same at Apple and at Google
    public const array STORE_LOCALES = ['fr' => 'fr-FR', 'en' => 'en-US', 'es' => 'es-ES'];

    /**
     * @param array<string, string> $names
     * @param array<string, string> $descriptions
     */
    public function __construct(
        public string $productId,
        public int $price,
        public array $names,
        public array $descriptions = [],
        public ?string $screenshot = null,
    ) {
        // Both stores refuse anything else, Google a hyphen included, and Apple never lets an id once used be taken again
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._]{0,99}$/', $productId)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a product id both stores accept', $productId));
        }
    }

    // A site's slug turned into an id both stores accept, so a product id is derived and never typed: "chateau-hurlton-orage" under "back" gives "back.chateau_hurlton_orage"
    public static function idFor(string $prefix, string $slug): string
    {
        return $prefix . '.' . str_replace('-', '_', $slug);
    }
}
