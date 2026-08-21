<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Service\StylesheetProvider;
use PHPUnit\Framework\TestCase;

class StylesheetProviderTest extends TestCase
{
    // The basket's own stylesheet is contributed to UiBundle - without it the basket table renders unstyled on a site running Payment without Shop
    public function testGetStylesheetsReturnsTheBundleStylesheet(): void
    {
        $this->assertSame(['bundles/c975lpayment/css/styles.min.css'], new StylesheetProvider()->getStylesheets());
    }

    // The path is served from public/, and a rename there leaves the site loading a 404 the browser reports nowhere
    public function testTheStylesheetItAnnouncesIsShipped(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/css/styles.min.css');
    }
}
