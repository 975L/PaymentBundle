<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Command;

use c975L\PaymentBundle\Command\RemindAbandonedBasketsCommand;
use c975L\PaymentBundle\Service\BasketReminderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The console entry point to BasketReminderService, run unattended by the scheduler: how many e-mails went out is the only trace of that run
class RemindAbandonedBasketsCommandTest extends TestCase
{
    public function testTheRemindersSentAreCounted(): void
    {
        $tester = $this->tester(4);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('4 reminder(s) sent', $tester->getDisplay());
    }

    // A night with nothing to remind is the ordinary case, and it succeeds - a non-zero status would have the scheduler report a failure every day
    public function testANightWithNothingToRemindStillSucceeds(): void
    {
        $tester = $this->tester(0);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('0 reminder(s) sent', $tester->getDisplay());
    }

    private function tester(int $count): CommandTester
    {
        $reminderService = $this->createMock(BasketReminderService::class);
        $reminderService->expects($this->once())->method('send')->willReturn($count);

        return new CommandTester(new RemindAbandonedBasketsCommand($reminderService));
    }
}
