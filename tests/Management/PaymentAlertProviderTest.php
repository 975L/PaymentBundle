<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Management\PaymentAlertProvider;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The dashboard says what the checkout would otherwise say to a customer: whether the shop can charge at all
class PaymentAlertProviderTest extends TestCase
{
    public function testAConfiguredGatewayRaisesNothing(): void
    {
        $this->assertSame([], $this->provider('stripe', true, false)->getAlerts());
    }

    public function testAGatewayWithoutItsKeysIsAlerted(): void
    {
        $alerts = $this->provider('stripe', false, false)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.payment_keys_missing', $alerts[0]['label']);
        $this->assertSame('description.payment_keys_missing', $alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
    }

    // The two pairs are two settings, and the one being read is the one to fill in - naming the live keys to a shop rehearsing sends the shopkeeper to the wrong entry
    public function testTheTestModeNamesTheTestKeys(): void
    {
        $alerts = $this->provider('stripe', false, true)->getAlerts();

        $this->assertSame('description.payment_keys_missing_test', $alerts[0]['description']);
    }

    // "payment-gateway" left empty or misspelled: getActive() throws on it, and a provider throwing takes every other bundle's alerts down with it
    public function testAnUnknownGatewayIsAlertedRatherThanThrown(): void
    {
        $alerts = $this->provider('paypal', true, false)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.payment_gateway_unavailable', $alerts[0]['label']);
    }

    private function provider(string $activeSlug, bool $configured, bool $testMode): PaymentAlertProvider
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('isConfigured')->willReturn($configured);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => match ($slug) {
            'payment-gateway' => $activeSlug,
            'site-role-admin' => 'ROLE_ADMIN',
            default => null,
        });

        $mode = $this->createStub(PaymentTestModeInterface::class);
        $mode->method('isEnabled')->willReturn($testMode);

        // The stub hands the key back untranslated, which is what the assertions read
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $urlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $urlGenerator->method('unsetAll')->willReturnSelf();
        $urlGenerator->method('setController')->willReturnSelf();
        $urlGenerator->method('setAction')->willReturnSelf();
        $urlGenerator->method('set')->willReturnSelf();
        $urlGenerator->method('generateUrl')->willReturn('/admin/config');

        return new PaymentAlertProvider(
            new PaymentGatewayRegistry([$gateway], $configService),
            $mode,
            $configService,
            $urlGenerator,
            $translator,
        );
    }
}
