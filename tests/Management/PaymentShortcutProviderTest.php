<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Management\PaymentShortcutProvider;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The dashboard tile says what pressing it does, not what the site currently charges with - a tile reading "enable" on a site already in test mode would put it back on the live keys
class PaymentShortcutProviderTest extends TestCase
{
    public function testTheTileOffersToEnableWhenThePaymentsAreReal(): void
    {
        $shortcut = $this->shortcut(false);

        $this->assertSame('label.payment_test_mode_enable', $shortcut['label']);
        $this->assertFalse($shortcut['active']);
    }

    public function testTheTileOffersToDisableWhenThePaymentsAreInTestMode(): void
    {
        $shortcut = $this->shortcut(true);

        $this->assertSame('label.payment_test_mode_disable', $shortcut['label']);
        $this->assertTrue($shortcut['active']);
    }

    private function shortcut(bool $enabled): array
    {
        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($enabled);

        // The stub hands the key back untranslated, which is what the assertions read
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new PaymentShortcutProvider($translator, $this->createStub(ConfigServiceInterface::class), $testMode)->getShortcuts()[0];
    }
}
