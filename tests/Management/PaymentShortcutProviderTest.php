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

    // Same rule for the documents tile, which says what it will do and not what the shop currently sends
    public function testTheDocumentsTileOffersToEnableWhileNothingIsAttached(): void
    {
        $shortcut = $this->shortcuts(attachments: false)[1];

        $this->assertSame('label.payment_email_attachments_enable', $shortcut['label']);
        $this->assertFalse($shortcut['active']);
    }

    public function testTheDocumentsTileOffersToDisableOnceTheyAreSent(): void
    {
        $shortcut = $this->shortcuts(attachments: true)[1];

        $this->assertSame('label.payment_email_attachments_disable', $shortcut['label']);
        $this->assertTrue($shortcut['active']);
    }

    // The one tile whose warning does not follow its 'active': an order confirmed without its invoice and without the terms the customer just accepted is the state worth an admin's eye, and that state is the tile being off
    public function testTheDocumentsTileIsPaintedWhileNothingIsAttached(): void
    {
        $this->assertTrue($this->shortcuts(attachments: false)[1]['warning']);
        $this->assertFalse($this->shortcuts(attachments: true)[1]['warning']);
    }

    private function shortcut(bool $enabled): array
    {
        return $this->shortcuts($enabled)[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shortcuts(bool $enabled = false, bool $attachments = false): array
    {
        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($enabled);

        // Reads the stored string the way ConfigService does, "false" being what an untouched site holds
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([['payment-email-attachments', $attachments ? 'true' : 'false']]);
        $configService->method('getBool')->willReturnCallback(static fn ($value): bool => filter_var($value, \FILTER_VALIDATE_BOOLEAN));

        // The stub hands the key back untranslated, which is what the assertions read
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new PaymentShortcutProvider($translator, $configService, $testMode)->getShortcuts();
    }
}
