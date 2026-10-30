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
use c975L\PaymentBundle\Contract\InAppProduct;
use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Store\GooglePlayStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

// What the site takes as Google Play's word, and what it writes there
class GooglePlayStoreTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    private array $calls = [];

    // One RSA key for the whole class, generating one taking a good part of a second
    private static string $pem = '';

    // Recorded under its purchase token, the one key a purchase and its voided notification always both carry
    public function testAPurchaseIsReadFromTheAndroidPublisherApi(): void
    {
        $transaction = $this->store(['purchaseState' => 0, 'orderId' => 'GPA.1', 'obfuscatedExternalAccountId' => 'uuid-1', 'purchaseType' => 0, 'acknowledgementState' => 0])
            ->verifyPurchase(['productId' => 'back.a', 'purchaseToken' => 'tok']);

        $this->assertSame('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.app/purchases/products/back.a/tokens/tok', $this->calls[1]['url']);
        $this->assertSame(['token.' . sha1('tok'), 'uuid-1', 'sandbox', 'tok', false], [$transaction->transactionId, $transaction->accountToken, $transaction->environment, $transaction->purchaseToken, $transaction->acknowledged]);
    }

    // Pending - paid in cash at a shop, say - or cancelled: nothing to deliver yet
    public function testAPurchaseNotCompletedGivesNothing(): void
    {
        $this->assertNull($this->store(['purchaseState' => 2])->verifyPurchase(['productId' => 'back.a', 'purchaseToken' => 'tok']));
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        $this->store(['error' => 'not found'], 404)->verifyPurchase(['productId' => 'back.a', 'purchaseToken' => 'tok']);
    }

    // Google refunds a purchase left unacknowledged for three days
    public function testAnUnacknowledgedPurchaseIsAcknowledged(): void
    {
        $this->store([])->acknowledge(new InAppTransaction('google', 'back.a', 'GPA.1', purchaseToken: 'tok', acknowledged: false));

        $this->assertStringEndsWith('/purchases/products/back.a/tokens/tok:acknowledge', $this->calls[1]['url']);
    }

    public function testAVoidedPurchaseNotificationIsARefund(): void
    {
        $transaction = $this->store([])->readNotification($this->notification(['packageName' => 'com.example.app', 'voidedPurchaseNotification' => ['purchaseToken' => 'tok', 'orderId' => 'GPA.1']]));

        $this->assertSame(PaymentEvent::Refunded, $transaction?->event);
        $this->assertSame('token.' . sha1('tok'), $transaction->transactionId);
    }

    // A service account not invited yet on the app: Google is unavailable, the purchase is not false
    public function testARefusedServiceAccountMakesTheStoreUnavailable(): void
    {
        $this->expectException(PaymentUnavailableException::class);

        $this->store(['error' => 'forbidden'], 403)->verifyPurchase(['productId' => 'back.a', 'purchaseToken' => 'tok']);
    }

    public function testAFailedAcknowledgementMakesTheStoreUnavailable(): void
    {
        $this->expectException(PaymentUnavailableException::class);

        $this->store(['error' => 'unavailable'], 503)->acknowledge(new InAppTransaction('google', 'back.a', 'token.x', purchaseToken: 'tok', acknowledged: false));
    }

    // The push url is public and Pub/Sub signs nothing the site checks: the token in the url is what tells Google from anybody
    public function testANotificationWithoutTheTokenIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        $this->store([])->readNotification($this->notification(['packageName' => 'com.example.app'], 'wrong'));
    }

    public function testATestNotificationAsksNothing(): void
    {
        $this->assertNull($this->store([])->readNotification($this->notification(['packageName' => 'com.example.app', 'testNotification' => ['version' => '1.0']])));
    }

    // One upsert call: allowMissing creates, the French price in micros, Google converting the rest
    public function testAProductIsWrittenInOneCall(): void
    {
        $this->store([])->publishProduct(new InAppProduct('back.a', 200, ['fr' => 'Dos A', 'en' => 'Back A']));

        $this->assertSame('PUT', $this->calls[1]['method']);
        $this->assertStringContainsString('/inappproducts/back.a?allowMissing=true&autoConvertMissingPrices=true', $this->calls[1]['url']);
        $this->assertSame(['priceMicros' => '2000000', 'currency' => 'EUR'], $this->calls[1]['body']['defaultPrice']);
        $this->assertSame(['fr-FR', 'en-US'], array_keys($this->calls[1]['body']['listings']));
    }

    /** @param array<string, mixed> $data */
    private function notification(array $data, string $token = 'secret'): Request
    {
        return Request::create('/payment/store/google?token=' . $token, 'POST', content: (string) json_encode(['message' => ['data' => base64_encode((string) json_encode($data))]]));
    }

    /** @param array<string, mixed> $answer what the API answers after the access token */
    private function store(array $answer, int $status = 200): GooglePlayStore
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answer, $status) {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? 'null', true)];

            return str_contains($url, 'oauth2') ? new MockResponse('{"access_token":"at"}') : new MockResponse((string) json_encode($answer), ['http_code' => $status]);
        });
        if ('' === self::$pem) {
            openssl_pkey_export(openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), self::$pem);
        }
        $values = [
            'google-play-package-name' => 'com.example.app',
            'google-play-service-account' => json_encode(['client_email' => 'play@example.iam.gserviceaccount.com', 'private_key' => self::$pem, 'token_uri' => 'https://oauth2.googleapis.com/token']),
            'google-play-notification-token' => 'secret',
        ];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);

        return new GooglePlayStore($configService, $client);
    }
}
