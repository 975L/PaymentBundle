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
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The dashboard says what the checkout would otherwise say to a customer: whether the shop can charge at all
class PaymentAlertProviderTest extends TestCase
{
    public function testAConfiguredGatewayKeepingACopyOfItsEmailsRaisesNothing(): void
    {
        $this->assertSame([], $this->provider('stripe', true, false)->getAlerts());
    }

    // The blind copy is the shopkeeper's only record of the confirmations and download links that went out, and left empty it fails silently - the email simply leaves without it
    public function testAnEmptyBlindCopyIsAlerted(): void
    {
        $alerts = $this->provider('stripe', true, false, '')->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.shop_email_bcc_missing', $alerts[0]['label']);
        $this->assertSame('description.shop_email_bcc_missing', $alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }

    // A shop that can charge nothing and keeps no copy has two things to fix, and says both
    public function testTheGatewayAndTheBlindCopyAreSaidTogether(): void
    {
        $alerts = $this->provider('stripe', false, false, '')->getAlerts();

        $this->assertSame(
            ['label.payment_keys_missing', 'label.shop_email_bcc_missing'],
            array_column($alerts, 'label'),
        );
    }

    // A partial refund leaves the order paid, and only the shopkeeper can say whether it should have taken the access back
    public function testRecentPartialRefundsAreAlerted(): void
    {
        $alerts = $this->provider('stripe', true, false, partialRefunds: 2)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.partial_refunds', $alerts[0]['label']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }

    // The alert counts orders the index would otherwise list among all the others: it opens on those money went back on
    public function testThePartialRefundsAlertOpensOnTheRefundedOrders(): void
    {
        $calls = [];
        $urlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $urlGenerator->method('unsetAll')->willReturnSelf();
        $urlGenerator->method('setController')->willReturnSelf();
        $urlGenerator->method('setAction')->willReturnSelf();
        $urlGenerator->method('generateUrl')->willReturn('/admin/basket');
        $urlGenerator->method('set')->willReturnCallback(function (string $name, mixed $value) use (&$calls, $urlGenerator) {
            $calls[] = [$name, $value];

            return $urlGenerator;
        });

        $this->provider('stripe', true, false, urlGenerator: $urlGenerator, partialRefunds: 2)->getAlerts();

        $this->assertSame([['filters', ['refundedAmount' => ['comparison' => '>', 'value' => 0]]]], $calls);
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

    // Both entries to fill in sit in the payment group, and the keys alert alone needs its sensitive values shown - the bcc is not sensitive, and asking for it to be revealed would show keys nothing asked for
    public function testEachAlertLinksToTheGroupHoldingItsOwnEntry(): void
    {
        $calls = [];
        $urlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $urlGenerator->method('unsetAll')->willReturnSelf();
        $urlGenerator->method('setController')->willReturnSelf();
        $urlGenerator->method('setAction')->willReturnSelf();
        $urlGenerator->method('generateUrl')->willReturn('/admin/config');
        $urlGenerator->method('set')->willReturnCallback(function (string $name, mixed $value) use (&$calls, $urlGenerator) {
            $calls[] = [$name, $value];

            return $urlGenerator;
        });

        $this->provider('stripe', false, false, '', $urlGenerator)->getAlerts();

        $this->assertSame(
            [['group', Config::GROUP_PAYMENT], ['showSensitive', 1], ['group', Config::GROUP_PAYMENT]],
            $calls,
        );
    }

    private function provider(string $activeSlug, bool $configured, bool $testMode, string $bcc = 'archive@shop.test', ?AdminUrlGeneratorInterface $urlGenerator = null, int $partialRefunds = 0): PaymentAlertProvider
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('countPartiallyRefundedSince')->willReturn($partialRefunds);

        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('isConfigured')->willReturn($configured);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => match ($slug) {
            'payment-gateway' => $activeSlug,
            'site-role-admin' => 'ROLE_ADMIN',
            'shop-email-bcc' => $bcc,
            default => null,
        });

        $mode = $this->createStub(PaymentTestModeInterface::class);
        $mode->method('isEnabled')->willReturn($testMode);

        // The stub hands the key back untranslated, which is what the assertions read
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        if (null === $urlGenerator) {
            $urlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
            $urlGenerator->method('unsetAll')->willReturnSelf();
            $urlGenerator->method('setController')->willReturnSelf();
            $urlGenerator->method('setAction')->willReturnSelf();
            $urlGenerator->method('set')->willReturnSelf();
            $urlGenerator->method('generateUrl')->willReturn('/admin/config');
        }

        return new PaymentAlertProvider(
            new PaymentGatewayRegistry([$gateway], $configService),
            $mode,
            $configService,
            $urlGenerator,
            $translator,
            $basketRepository,
        );
    }
}
