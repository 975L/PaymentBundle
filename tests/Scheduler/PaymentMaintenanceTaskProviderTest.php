<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Scheduler;

use c975L\ConfigBundle\Scheduler\MaintenanceTask;
use c975L\PaymentBundle\Command\BasketRetentionCommand;
use c975L\PaymentBundle\Command\RemindAbandonedBasketsCommand;
use c975L\PaymentBundle\Scheduler\PaymentMaintenanceTaskProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

// What a site installing this bundle gets on its schedule without listing anything itself
class PaymentMaintenanceTaskProviderTest extends TestCase
{
    // Two tasks: what the retention rules take away at night, and what the customers are reminded of in the morning
    public function testItDeclaresBothTasks(): void
    {
        $tasks = new PaymentMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertCount(2, $tasks);
        $this->assertContainsOnlyInstancesOf(MaintenanceTask::class, $tasks);
        $this->assertSame('c975l:payment:baskets:retention', $tasks[0]->command);
        $this->assertSame('# #(1-3) * * *', $tasks[0]->expression);
        $this->assertSame('c975l:payment:baskets:remind', $tasks[1]->command);
        $this->assertSame('# #(8-9) * * *', $tasks[1]->expression);
    }

    // A schedule naming a command that no longer exists fails at night, on a site, with nobody reading it
    public function testTheCommandsItSchedulesExist(): void
    {
        $tasks = new PaymentMaintenanceTaskProvider()->getMaintenanceTasks();
        $classes = [BasketRetentionCommand::class, RemindAbandonedBasketsCommand::class];

        foreach ($classes as $index => $class) {
            $attributes = new \ReflectionClass($class)->getAttributes(AsCommand::class);

            $this->assertCount(1, $attributes);
            $this->assertSame($tasks[$index]->command, $attributes[0]->newInstance()->name);
        }
    }
}
