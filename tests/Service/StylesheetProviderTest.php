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

    // The sheet drawing this bundle's kinds, loaded in the back-office by UiBundle's picker and by any site page listing what the bundle offers
    public function testTheSilhouetteSheetIsAdvertisedAndShipped(): void
    {
        $stylesheets = new StylesheetProvider()->getManagementStylesheets();

        $this->assertSame(['bundles/c975lpayment/css/block-thumbs.min.css'], $stylesheets);
        $this->assertFileExists(\dirname(__DIR__, 2) . '/public/css/block-thumbs.min.css', 'The silhouettes sass has not been compiled.');
    }

    // A kind shipped without its silhouette falls back to UiBundle's default one - a title and two lines, the same frame as every other kind left undrawn, which is exactly what the picker exists to avoid
    public function testEveryPickableKindHasItsOwnSilhouette(): void
    {
        $sass = (string) file_get_contents(\dirname(__DIR__, 2) . '/sass/block-thumbs.scss');
        $kinds = $this->pickableKinds();

        // Read off services.yaml rather than listed here, so a kind added tomorrow is covered without touching this test - which also means an empty list would let it pass having checked nothing
        $this->assertNotEmpty($kinds, 'No "ui.block" tag was read from services.yaml, so this test checked nothing at all.');

        foreach ($kinds as $kind) {
            $this->assertStringContainsString('.ui-block-thumb--' . $kind, $sass, sprintf('The "%s" block has no silhouette, so the picker shows it as a bare frame.', $kind));
        }
    }

    /**
     * The kinds this bundle registers and the picker offers, read off its own tagged services.
     *
     * @return list<string>
     */
    private function pickableKinds(): array
    {
        $services = (string) file_get_contents(\dirname(__DIR__, 2) . '/config/services.yaml');

        preg_match_all('/- name: ui\\.block\\n((?:\\s+[\\w-]+: .*\\n|\\s+#.*\\n)+)/', $services, $matches);

        $kinds = [];
        foreach ($matches[1] as $tag) {
            if (1 === preg_match('/pickable: *.?false/', $tag)) {
                continue;
            }
            if (1 === preg_match('/kind: *(\\S+)/', $tag, $kind)) {
                $kinds[] = trim($kind[1], '"\'');
            }
        }

        return $kinds;
    }
}
