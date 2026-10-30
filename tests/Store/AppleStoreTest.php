<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Store;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Store\AppleCatalogWriter;
use c975L\PaymentBundle\Store\AppleJwsVerifier;
use c975L\PaymentBundle\Store\AppleStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

// What the site takes as Apple's word on a purchase and on a refund
class AppleStoreTest extends TestCase
{
    private AppleTestChain $chain;

    protected function setUp(): void
    {
        $this->chain = new AppleTestChain();
    }

    // Apple's review buys in the sandbox from the production app: a transaction production does not know is asked of the sandbox
    public function testATransactionUnknownInProductionIsAskedOfTheSandbox(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return str_contains($url, 'sandbox')
                ? new MockResponse((string) json_encode(['signedTransactionInfo' => $this->transaction(['environment' => 'Sandbox'])]))
                : new MockResponse('{}', ['http_code' => 404]);
        });

        $transaction = $this->store($client)->verifyPurchase(['transactionId' => '2000000001']);

        $this->assertSame(['https://api.storekit.itunes.apple.com/inApps/v1/transactions/2000000001', 'https://api.storekit-sandbox.itunes.apple.com/inApps/v1/transactions/2000000001'], $urls);
        $this->assertSame('back.chateau_hurlton_orage', $transaction->productId);
        $this->assertSame('2000000001', $transaction->transactionId);
        $this->assertSame('0f3a51c4-8f6e-8d55-9d1a-3c7e5f2b8a10', $transaction->accountToken);
        $this->assertSame('sandbox', $transaction->environment);
        $this->assertSame(PaymentEvent::Paid, $transaction->event);
    }

    public function testATransactionOfAnotherAppIsRefused(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['signedTransactionInfo' => $this->transaction(['bundleId' => 'com.other.app'])])));

        $this->expectException(InvalidNotificationException::class);
        $this->store($client)->verifyPurchase(['transactionId' => '2000000001']);
    }

    public function testAMalformedTransactionIdIsNotSentToApple(): void
    {
        $this->expectException(InvalidNotificationException::class);

        $this->store(new MockHttpClient(static fn () => throw new \LogicException('No call expected')))->verifyPurchase(['transactionId' => '../apps']);
    }

    public function testARefundNotificationTakesTheTransactionBack(): void
    {
        $request = Request::create('/payment/store/apple', 'POST', content: (string) json_encode(['signedPayload' => $this->chain->sign([
            'notificationType' => 'REFUND',
            'data' => ['signedTransactionInfo' => $this->transaction()],
        ])]));

        $transaction = $this->store()->readNotification($request);

        $this->assertSame(PaymentEvent::Refunded, $transaction?->event);
        $this->assertSame('2000000001', $transaction->transactionId);
    }

    // A refund Apple reversed gives back what it took
    public function testARefundReversedNotificationPaysTheTransactionAgain(): void
    {
        $request = Request::create('/payment/store/apple', 'POST', content: (string) json_encode(['signedPayload' => $this->chain->sign([
            'notificationType' => 'REFUND_REVERSED',
            'data' => ['signedTransactionInfo' => $this->transaction()],
        ])]));

        $this->assertSame(PaymentEvent::Paid, $this->store()->readNotification($request)?->event);
    }

    // A key revoked: Apple is unavailable, the purchase is not false
    public function testARefusedKeyMakesTheStoreUnavailable(): void
    {
        $this->expectException(PaymentUnavailableException::class);

        $this->store(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])))->verifyPurchase(['transactionId' => '2000000001']);
    }

    // A purchase, a consumption request, a test from App Store Connect: authentic, but nothing to take back
    public function testAnotherNotificationAsksNothing(): void
    {
        $request = Request::create('/payment/store/apple', 'POST', content: (string) json_encode(['signedPayload' => $this->chain->sign(['notificationType' => 'TEST', 'data' => []])]));

        $this->assertNull($this->store()->readNotification($request));
    }

    public function testAnUnsignedNotificationIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        $this->store()->readNotification(Request::create('/payment/store/apple', 'POST', content: '{"notificationType":"REFUND"}'));
    }

    // Verifying needs the In-App Purchase key; the team key alone writes the catalogue but verifies nothing
    public function testVerifyingNeedsTheInAppPurchaseKey(): void
    {
        $this->assertTrue($this->store()->isConfigured());
        $this->assertFalse($this->store(config: ['apple-iap-private-key' => null])->isConfigured());
    }

    /** @param array<string, mixed> $overrides */
    private function transaction(array $overrides = []): string
    {
        return $this->chain->sign($overrides + [
            'bundleId' => 'com.example.app',
            'productId' => 'back.chateau_hurlton_orage',
            'transactionId' => '2000000001',
            'appAccountToken' => '0F3A51C4-8F6E-8D55-9D1A-3C7E5F2B8A10',
            'environment' => 'Production',
        ]);
    }

    /** @param array<string, mixed> $config */
    private function store(?MockHttpClient $client = null, array $config = []): AppleStore
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $values = $config + ['apple-bundle-id' => 'com.example.app', 'apple-issuer-id' => 'issuer', 'apple-iap-key-id' => 'K1', 'apple-iap-private-key' => $pem];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);
        $client ??= new MockHttpClient();

        return new AppleStore($configService, $client, new AppleJwsVerifier($this->chain->rootPath), new AppleCatalogWriter($configService, $client));
    }
}
