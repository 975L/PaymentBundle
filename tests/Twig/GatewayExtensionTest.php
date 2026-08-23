<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Twig\GatewayExtension;
use PHPUnit\Framework\TestCase;

// What a page may say about how the shop is paid, which a single config value no longer answers
class GatewayExtensionTest extends TestCase
{
    public function testTheOfferedSlugsAreListedInTheOrderTheRegistryGivesThem(): void
    {
        $registry = $this->createStub(PaymentGatewayRegistry::class);
        $registry->method('getOffered')->willReturn(['revolut' => new \stdClass(), 'stripe' => new \stdClass()]);

        $this->assertSame(['revolut', 'stripe'], new GatewayExtension($registry)->gateways());
    }

    // A shop holding no key at all offers nothing, and the components keyed on a provider draw nothing
    public function testAShopWithNoKeysListsNothing(): void
    {
        $registry = $this->createStub(PaymentGatewayRegistry::class);
        $registry->method('getOffered')->willReturn([]);

        $this->assertSame([], new GatewayExtension($registry)->gateways());
    }
}
