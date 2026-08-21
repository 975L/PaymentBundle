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

// The commands this bundle needs run on a cadence, declared here rather than listed by every site in its own MaintenanceSchedule - c975l:shop:baskets:delete lives here and not in ShopBundle, baskets being this bundle's own
class PaymentMaintenanceTaskProvider implements MaintenanceTaskProviderInterface
{
    public function getMaintenanceTasks(): array
    {
        return [
            // Baskets left unvalidated, nightly
            new MaintenanceTask('# #(1-3) * * *', 'c975l:shop:baskets:delete'),
        ];
    }
}
