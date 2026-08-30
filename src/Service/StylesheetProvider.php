<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\UiBundle\Contract\BundleStylesheetManagementProviderInterface;
use c975L\UiBundle\Contract\BundleStylesheetProviderInterface;

class StylesheetProvider implements BundleStylesheetProviderInterface, BundleStylesheetManagementProviderInterface
{
    public function getStylesheets(): array
    {
        return [
            'bundles/c975lpayment/css/styles.min.css',
        ];
    }

    // The silhouettes of this bundle's block kinds (see sass/block-thumbs.scss), for the visual picker of the back-office. A site showing them on a public page - a block showcase - contributes the same file through its own stylesheet provider, rather than every site carrying it on every page for the one that has such a page.
    public function getManagementStylesheets(): array
    {
        return [
            'bundles/c975lpayment/css/block-thumbs.min.css',
        ];
    }
}
