<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Service\PaymentTestMode;
use PHPUnit\Framework\TestCase;

// The one answer telling a real order from a rehearsal, which nothing may guess from a key any more
class PaymentTestModeTest extends TestCase
{
    public function testItReadsTheStatedSetting(): void
    {
        $this->assertTrue($this->testMode(true)->isEnabled());
        $this->assertFalse($this->testMode(false)->isEnabled());
    }

    // A site that never set the entry charges for real, which is the only safe way round for a default
    public function testAnUnsetSettingIsNotTestMode(): void
    {
        $this->assertFalse($this->testMode(null)->isEnabled());
    }

    private function testMode(?bool $enabled): PaymentTestMode
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($enabled);

        return new PaymentTestMode($configService);
    }
}
