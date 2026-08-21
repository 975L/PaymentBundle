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
use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\PaymentBundle\Management\LinkableRouteProvider;
use c975L\PaymentBundle\Management\MenuProvider;
use c975L\PaymentBundle\Management\PaymentShortcutProvider;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Every CRUD controller and route this bundle's management providers name, checked against what its controllers actually declare - see ConfigBundle's ManagementTargetsTestCase
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        return [
            new MenuProvider(),
            new LinkableRouteProvider(),
            new PaymentShortcutProvider(
                $this->createStub(TranslatorInterface::class),
                $this->createStub(ConfigServiceInterface::class),
                $this->createStub(PaymentTestModeInterface::class),
            ),
        ];
    }

    // This bundle's own controllers on top of ConfigBundle's: the management ones carry the routes its menus and its test-mode tile name, and the front ones the basket page its linkable route offers
    #[\Override]
    protected function controllerDirectories(): array
    {
        return [...parent::controllerDirectories(), __DIR__ . '/../../src/Controller', __DIR__ . '/../../src/Controller/Management'];
    }
}
