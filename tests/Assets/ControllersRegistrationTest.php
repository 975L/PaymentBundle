<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// Guards assets/controllers.js, the front-end Stimulus barrel - the repository has no browser to load it in, so a barrel starting no app is silent here and leaves every add button of the ecosystem dead in a consuming app
class ControllersRegistrationTest extends TestCase
{
    private const string BARREL = 'assets/controllers.js';

    // The barrel starts its own app, as every c975L bundle does since the register(app) barrels were dropped
    public function testTheBarrelStartsItsOwnStimulusApp(): void
    {
        $barrel = $this->read();

        $this->assertStringContainsString('startStimulusApp()', $barrel, 'The barrel starts no Stimulus app, so nothing registers its controllers.');
        $this->assertStringNotContainsString('export function register', $barrel, 'The barrel still exports register(), which no consuming app calls any more.');
    }

    // The identifier every template writes in its data-controller, this bundle's basket pages as much as the add buttons ShopBundle draws - registered lazily, the layout loading this barrel site-wide while the basket lives on the shop pages alone
    public function testTheBasketControllerIsRegisteredAsALazyFrontController(): void
    {
        $this->assertStringContainsString("basket: () => import('./js/basket.js'),", $this->read());
    }

    // Registering on load alone leaves a page reached by a Turbo navigation without its controllers, the module never running again
    public function testTheLazyControllersAreRegisteredAgainOnTurboLoad(): void
    {
        $barrel = $this->read();

        $this->assertStringContainsString('registerPresentControllers()', $barrel);
        $this->assertStringContainsString("document.addEventListener('turbo:load', registerPresentControllers);", $barrel);
    }

    // An import of a missing file leaves the identifier registered with nothing behind it, and every add button of the page dead
    public function testEveryLazyControllerIsShipped(): void
    {
        preg_match_all("#import\('\./(js/[^']+\.js)'\)#", $this->read(), $matches);
        $this->assertNotSame([], $matches[1], 'The barrel imports no controller at all.');

        foreach ($matches[1] as $path) {
            $this->assertFileExists(\dirname(__DIR__, 2) . '/assets/' . $path, sprintf('The barrel imports "%s", which the bundle does not ship', $path));
        }
    }

    private function read(): string
    {
        $path = \dirname(__DIR__, 2) . '/' . self::BARREL;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
