<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Scheduler;

use c975L\ConfigBundle\Scheduler\MaintenanceTask;
use c975L\ConfigBundle\Scheduler\MaintenanceTaskProviderInterface;

// The commands this bundle needs run on a cadence, declared here rather than listed by every site in its own MaintenanceSchedule - both live here and not in ShopBundle, baskets being this bundle's own
class PaymentMaintenanceTaskProvider implements MaintenanceTaskProviderInterface
{
    public function getMaintenanceTasks(): array
    {
        return [
            // Everything the retention rules take away or set aside, nightly
            new MaintenanceTask('# #(1-3) * * *', 'c975l:payment:baskets:retention'),
            // Reminders for the orders left unpaid, mid-morning rather than in the small hours: it is an e-mail a customer reads, not a table the site tidies up
            new MaintenanceTask('# #(8-9) * * *', 'c975l:payment:baskets:remind'),
        ];
    }
}
