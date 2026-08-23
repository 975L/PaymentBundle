<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Command;

use c975L\PaymentBundle\Command\BasketRetentionCommand;
use c975L\PaymentBundle\Service\BasketRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The console entry point to BasketRetentionService: what it deleted and archived is a legal matter, so the run has to state it rather than pass silently
class BasketRetentionCommandTest extends TestCase
{
    public function testEachStepIsCountedInTheReport(): void
    {
        $retentionService = $this->createMock(BasketRetentionService::class);
        $retentionService->expects($this->once())->method('run')->willReturn([
            'unvalidated' => 12,
            'abandoned' => 3,
            'archived' => 7,
            'expired' => 1,
        ]);

        $tester = new CommandTester(new BasketRetentionCommand($retentionService));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        foreach (['12', '3', '7', '1'] as $count) {
            $this->assertStringContainsString($count, $display);
        }
    }

    // The durations are constants of the service and not settings of each site, and the report names them so the run says what rule it applied
    public function testTheReportNamesTheDurationsApplied(): void
    {
        $retentionService = $this->createMock(BasketRetentionService::class);
        $retentionService->expects($this->once())->method('run')->willReturn(['unvalidated' => 0, 'abandoned' => 0, 'archived' => 0, 'expired' => 0]);

        $tester = new CommandTester(new BasketRetentionCommand($retentionService));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString((string) BasketRetentionService::UNVALIDATED_DAYS, $display);
        $this->assertStringContainsString((string) BasketRetentionService::RETENTION_YEARS, $display);
    }
}
