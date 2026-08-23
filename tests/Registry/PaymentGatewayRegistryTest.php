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

    // A provider is offered as soon as its keys are filled in, so a shop opens a second one by storing its keys and closes it by clearing them
    public function testOnlyTheProvidersHoldingKeysAreOffered(): void
    {
        $registry = $this->registry('stripe', configured: ['stripe']);

        $this->assertSame(['stripe'], array_keys($registry->getOffered()));
    }

    // The one the config names leads, which is the one the basket pre-selects
    public function testTheDefaultProviderIsOfferedFirst(): void
    {
        $registry = $this->registry('revolut', configured: ['stripe', 'revolut']);

        $this->assertSame(['revolut', 'stripe'], array_keys($registry->getOffered()));
    }

    // A default whose own keys are missing is simply not in the list, and the shop still charges through the other
    public function testADefaultWithoutKeysLeavesTheOthersOffered(): void
    {
        $registry = $this->registry('stripe', configured: ['revolut']);

        $this->assertSame(['revolut'], array_keys($registry->getOffered()));
    }

    public function testAShopWithNoKeysAtAllOffersNothing(): void
    {
        $this->assertSame([], $this->registry('stripe', configured: [])->getOffered());
    }

    private function registry(string $active, array $configured = ['stripe', 'revolut']): PaymentGatewayRegistry
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($active);

        return new PaymentGatewayRegistry(
            [$this->gateway('stripe', in_array('stripe', $configured, true)), $this->gateway('revolut', in_array('revolut', $configured, true))],
            $configService
        );
    }

    private function gateway(string $slug, bool $isConfigured = true): PaymentGatewayInterface
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn($slug);
        $gateway->method('isConfigured')->willReturn($isConfigured);

        return $gateway;
    }
}
