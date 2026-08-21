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
use c975L\PaymentBundle\Command\DeleteUnvalidatedBasketsCommand;
use c975L\PaymentBundle\Scheduler\PaymentMaintenanceTaskProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

// What a site installing this bundle gets on its schedule without listing anything itself
class PaymentMaintenanceTaskProviderTest extends TestCase
{
    // One task, the nightly sweep of the baskets nobody validated
    public function testItDeclaresTheBasketSweep(): void
    {
        $tasks = new PaymentMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertCount(1, $tasks);
        $this->assertInstanceOf(MaintenanceTask::class, $tasks[0]);
        $this->assertSame('c975l:shop:baskets:delete', $tasks[0]->command);
        $this->assertSame('# #(1-3) * * *', $tasks[0]->expression);
    }

    // A schedule naming a command that no longer exists fails at night, on a site, with nobody reading it
    public function testTheCommandItSchedulesExists(): void
    {
        $attributes = new \ReflectionClass(DeleteUnvalidatedBasketsCommand::class)->getAttributes(AsCommand::class);

        $this->assertCount(1, $attributes);
        $this->assertSame(
            new PaymentMaintenanceTaskProvider()->getMaintenanceTasks()[0]->command,
            $attributes[0]->newInstance()->name,
        );
    }
}
