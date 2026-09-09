<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\PaymentBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

// What the menu's own entries point at is ManagementTargetsTest's; this is the section they hang under, which nothing else reads
class MenuProviderTest extends TestCase
{
    // The drawer is drawn as a collapsible submenu carrying an icon like its items do (see ConfigBundle's MenuBuilder), and reads its caption in this bundle's own domain
    public function testTheSectionCarriesItsCaptionAndItsIcon(): void
    {
        $this->assertSame([
            'label' => 'label.payment',
            'translation_domain' => 'payment',
            'icon' => 'fas fa-credit-card',
        ], new MenuProvider()->getMenuSection());
    }

    // An entry drawn with no icon is a caption with a gap where its neighbours have one
    public function testEveryEntryCarriesAnIcon(): void
    {
        foreach (new MenuProvider()->getMenus() as $slug => $menu) {
            $this->assertArrayHasKey('icon', $menu, sprintf('The "%s" entry is drawn without an icon.', $slug));
            $this->assertNotSame('', $menu['icon']);
        }
    }
}
