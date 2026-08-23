<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Controller\Management\PaymentShortcutController;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Grouped under maintenance, alongside the site's own maintenance switch: both put a part of the site into a state it is not meant to serve customers in, and an admin looks for them in the same place
class PaymentShortcutProvider implements ShortcutProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ConfigServiceInterface $configService,
        private readonly PaymentTestModeInterface $testMode,
    ) {
    }

    public function getShortcuts(): array
    {
        $enabled = $this->testMode->isEnabled();

        return [
            [
                'label' => $this->translator->trans(
                    $enabled ? 'label.payment_test_mode_disable' : 'label.payment_test_mode_enable',
                    [],
                    'payment',
                ),
                'icon' => 'fas fa-vial',
                'route' => PaymentShortcutController::TOGGLE_ROUTE_TEST_MODE,
                'active' => $enabled,
                'role' => $this->configService->get('site-role-admin'),
                'category' => ShortcutProviderInterface::CATEGORY_TOGGLE,
            ],
        ];
    }
}
