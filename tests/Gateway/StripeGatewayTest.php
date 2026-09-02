<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Gateway;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Gateway\StripeGateway;
use c975L\PaymentBundle\Gateway\StripeSessionReader;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Symfony\Component\HttpFoundation\Request;

// Which pair of keys Stripe is called with, and what a customer's payment links to in the back-office, both following the stated test mode alone
class StripeGatewayTest extends TestCase
{
    // ApiRequestor holds its client statically, so a stub left in place would silently answer the next Stripe test rather than its own
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function testTestModeChargesWithTheTestKeys(): void
    {
        $configService = $this->configService(['stripe-secret' => 'sk_live_1', 'stripe-secret-test' => 'sk_test_1']);

        $this->assertTrue($this->gateway($configService, true)->isConfigured());
    }

    // The live key being set is no help when the test one is missing: the checkout would open on the wrong account
    public function testTestModeWithoutATestKeyIsNotConfigured(): void
    {
        $configService = $this->configService(['stripe-secret' => 'sk_live_1', 'stripe-secret-test' => null]);

        $this->assertFalse($this->gateway($configService, true)->isConfigured());
        $this->assertTrue($this->gateway($configService, false)->isConfigured());
    }

    // The test dashboard and the live one are two distinct spaces at Stripe, and a payment id is only found in its own
    public function testTheTransactionLinksToTheDashboardOfTheModeInUse(): void
    {
        $configService = $this->configService([]);

        $this->assertSame('https://dashboard.stripe.com/test/payments/pi_1', $this->gateway($configService, true)->getTransactionUrl('pi_1'));
        $this->assertSame('https://dashboard.stripe.com/payments/pi_1', $this->gateway($configService, false)->getTransactionUrl('pi_1'));
    }

    // An unsigned payload is anybody's, and marking a basket paid on it would hand the order away
    public function testAPayloadWithoutAValidSignatureIsRefused(): void
    {
        $configService = $this->configService(['stripe-webhook-secret' => 'whsec_1']);

        $this->expectException(InvalidNotificationException::class);

        $this->gateway($configService, false)->readNotification(new Request([], [], [], [], [], [], '{"type":"checkout.session.completed"}'));
    }

    // Without a key there is nothing to authenticate, and saying so beats letting Stripe answer a request made with an empty string
    public function testCredentialsAreNotVerifiedWhenNoKeyIsSet(): void
    {
        $configService = $this->configService(['stripe-secret' => null]);

        $this->assertSame('No secret key is set for the live mode', $this->gateway($configService, false)->verifyCredentials());
    }

    // A key restricted to Checkout alone is refused on the account endpoint, so verifying there would report a working key as broken: the check must ask for nothing a payment does not already need
    public function testCredentialsAreVerifiedOnCheckoutAndNotOnTheAccount(): void
    {
        $client = new class implements ClientInterface {
            public array $urls = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->urls[] = $absUrl;

                return ['{"object":"list","data":[],"has_more":false,"url":"/v1/checkout/sessions"}', 200, []];
            }
        };
        ApiRequestor::setHttpClient($client);

        $configService = $this->configService(['stripe-secret' => 'rk_live_1']);

        $this->assertNull($this->gateway($configService, false)->verifyCredentials());
        $this->assertCount(1, $client->urls);
        $this->assertStringContainsString('/v1/checkout/sessions', $client->urls[0]);
        $this->assertStringNotContainsString('/v1/account', $client->urls[0]);
    }

    private function gateway(ConfigServiceInterface $configService, bool $testMode): StripeGateway
    {
        $mode = $this->createStub(PaymentTestModeInterface::class);
        $mode->method('isEnabled')->willReturn($testMode);

        return new StripeGateway($configService, $mode, new StripeSessionReader());
    }

    private function configService(array $values): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => $values[$slug] ?? null);

        return $configService;
    }
}
