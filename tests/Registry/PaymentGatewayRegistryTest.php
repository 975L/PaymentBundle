<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Registry;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;

// What makes a second provider a matter of adding a class: the site charges with the one its payment-gateway config names
class PaymentGatewayRegistryTest extends TestCase
{
    public function testTheActiveGatewayIsTheOneTheConfigNames(): void
    {
        $registry = $this->registry('revolut');

        $this->assertSame('revolut', $registry->getActive()->getSlug());
    }

    public function testAGatewayIsFoundBySlug(): void
    {
        $registry = $this->registry('stripe');

        $this->assertTrue($registry->has('revolut'));
        $this->assertSame('revolut', $registry->get('revolut')->getSlug());
        $this->assertSame(['stripe', 'revolut'], $registry->getSlugs());
    }

    // A provider named by the config but not installed must say so rather than charge with another one
    public function testAnUnknownSlugIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registry('paypal')->getActive();
    }

    // What reads the config to decide rather than to charge (the dashboard alert, the basket refusing to validate) asks for it without having to guard the exception above
    public function testAnUnknownSlugIsNullRatherThanThrownWhenAsked(): void
    {
        $this->assertNull($this->registry('paypal')->getActiveOrNull());
        $this->assertSame('stripe', $this->registry('stripe')->getActiveOrNull()->getSlug());
    }

    private function registry(string $active): PaymentGatewayRegistry
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($active);

        return new PaymentGatewayRegistry([$this->gateway('stripe'), $this->gateway('revolut')], $configService);
    }

    private function gateway(string $slug): PaymentGatewayInterface
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn($slug);

        return $gateway;
    }
}
