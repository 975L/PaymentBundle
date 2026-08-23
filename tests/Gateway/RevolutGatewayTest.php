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
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Gateway\RevolutGateway;
use c975L\PaymentBundle\Gateway\RevolutOrderReader;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Which pair of keys Revolut is called with, and what the site accepts as its word that a basket is paid
class RevolutGatewayTest extends TestCase
{
    public function testTestModeChargesWithTheTestKeys(): void
    {
        $configService = $this->configService(['revolut-secret' => 'sk_live_1', 'revolut-secret-test' => 'sk_test_1']);

        $this->assertTrue($this->gateway($configService, testMode: true)->isConfigured());
    }

    // The live key being set is no help when the test one is missing: the checkout would open on the wrong space
    public function testTestModeWithoutATestKeyIsNotConfigured(): void
    {
        $configService = $this->configService(['revolut-secret' => 'sk_live_1', 'revolut-secret-test' => null]);

        $this->assertFalse($this->gateway($configService, testMode: true)->isConfigured());
        $this->assertTrue($this->gateway($configService, testMode: false)->isConfigured());
    }

    // The sandbox is a separate space with its own orders, and a live key is not accepted by it
    public function testTheCheckoutIsOpenedOnTheApiOfTheModeInUse(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? '[]', true), 'headers' => $options['headers']];

            return new MockResponse(json_encode(['id' => 'ord_1', 'checkout_url' => 'https://checkout.revolut.com/payment-link/tok_1']));
        });

        $session = $this->gateway($this->configService(['revolut-secret-test' => 'sk_test_1']), true, $client)->createCheckout($this->checkoutRequest());

        $this->assertSame('https://checkout.revolut.com/payment-link/tok_1', $session->url);
        $this->assertSame('ord_1', $session->reference);
        $this->assertSame('https://sandbox-merchant.revolut.com/api/orders', $requests[0]['url']);
        $this->assertContains('Authorization: Bearer sk_test_1', $requests[0]['headers']);
    }

    // Revolut charges one amount for the whole order, and hands nothing of the site's back but the merchant reference the basket is written into
    public function testTheCheckoutCarriesTheTotalAndTheBasketReference(): void
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return new MockResponse(json_encode(['id' => 'ord_1', 'checkout_url' => 'https://checkout.revolut.com/payment-link/tok_1']));
        });

        $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)->createCheckout($this->checkoutRequest());

        $this->assertSame(2500, $body['amount']);
        $this->assertSame('EUR', $body['currency']);
        $this->assertSame('42', $body['merchant_order_data']['reference']);
        $this->assertSame('https://example.com/paid', $body['redirect_url']);
        $this->assertSame('buyer@example.com', $body['customer']['email']);
    }

    // An answer accepted by Revolut but naming no page to pay on would send the customer to a blank url
    public function testAnAnswerNamingNoCheckoutPageIsRefused(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['id' => 'ord_1'])));

        $this->expectException(PaymentUnavailableException::class);

        $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)->createCheckout($this->checkoutRequest());
    }

    // An unsigned payload is anybody's, and marking a basket paid on it would hand the order away
    public function testAPayloadWithoutAValidSignatureIsRefused(): void
    {
        $configService = $this->configService(['revolut-webhook-secret' => 'wsk_1']);

        $this->expectException(InvalidNotificationException::class);

        $this->gateway($configService, false)->readNotification($this->webhookRequest('{"event":"ORDER_COMPLETED"}', 'wsk_wrong'));
    }

    // The signature stays valid for as long as the secret does, so an event caught on the wire and posted again a day later is only stopped by its timestamp
    public function testAnEventOutsideTheTimeWindowIsRefused(): void
    {
        $configService = $this->configService(['revolut-webhook-secret' => 'wsk_1']);

        $this->expectException(InvalidNotificationException::class);

        $this->gateway($configService, false)->readNotification(
            $this->webhookRequest('{"event":"ORDER_COMPLETED","order_id":"ord_1"}', 'wsk_1', (time() - 3600) * 1000)
        );
    }

    // A secret being rotated has Revolut signing the same event with both, and either matching is enough
    public function testEitherSignatureOfARotatedSecretIsAccepted(): void
    {
        $payload = '{"event":"ORDER_COMPLETED","order_id":"ord_1"}';
        $timestamp = time() * 1000;
        $request = $this->webhookRequest($payload, 'wsk_1', $timestamp);
        $request->headers->set('revolut-signature', 'v1=deadbeef,' . $request->headers->get('revolut-signature'));

        $client = new MockHttpClient(new MockResponse(json_encode([
            'id' => 'ord_1',
            'state' => 'completed',
            'amount' => 2500,
            'merchant_order_ext_ref' => '42',
        ])));

        $notification = $this->gateway($this->configService(['revolut-webhook-secret' => 'wsk_1', 'revolut-secret' => 'sk_live_1']), false, $client)->readNotification($request);

        $this->assertSame('42', $notification?->basketId);
    }

    // Every other event the webhook is subscribed to is authentic and of no interest here, and Revolut is not called about it
    public function testAnEventThatIsNotAnOrderCompletionConfirmsNothing(): void
    {
        $payload = '{"event":"ORDER_CANCELLED","order_id":"ord_1"}';
        $client = new MockHttpClient(fn () => $this->fail('Revolut must not be called for an event that settles nothing'));

        $notification = $this->gateway($this->configService(['revolut-webhook-secret' => 'wsk_1']), false, $client)
            ->readNotification($this->webhookRequest($payload, 'wsk_1'));

        $this->assertNull($notification);
    }

    // Revolut sends the customer back on a url the site chose before the order existed: without the reference kept on the payment there is nothing to look up
    public function testAReturnWithoutAReferenceLooksNothingUp(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('Revolut must not be called with no order to ask about'));

        $this->assertNull($this->gateway($this->configService([]), false, $client)->readReturn(new Request(), null));
    }

    // Revolut offers no screen to declare a webhook and answers its secret once: the call is made here so the command on top of it has something to store
    public function testDeclaringTheWebhookAnswersTheSigningSecret(): void
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return new MockResponse(json_encode(['id' => 'wh_1', 'signing_secret' => 'wsk_1']));
        });

        $secret = $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)
            ->registerWebhook('https://example.com/payment/webhook/revolut');

        $this->assertSame('wsk_1', $secret);
        $this->assertSame('https://example.com/payment/webhook/revolut', $body['url']);
        $this->assertSame(['ORDER_COMPLETED'], $body['events']);
    }

    // A webhook declared without a secret to check its events with settles nothing, and would look like a finished setup
    public function testAWebhookDeclaredWithoutASecretIsRefused(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['id' => 'wh_1'])));

        $this->expectException(PaymentUnavailableException::class);

        $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)
            ->registerWebhook('https://example.com/payment/webhook/revolut');
    }

    // Revolut takes a second webhook on the same url rather than replacing the first, so declaring one starts by reading what is already there
    public function testTheWebhooksAlreadyDeclaredAreListed(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            ['id' => 'wh_1', 'url' => 'https://example.com/payment/webhook/revolut', 'events' => ['ORDER_COMPLETED']],
        ])));

        $webhooks = $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)->listWebhooks();

        $this->assertSame('wh_1', $webhooks[0]['id']);
    }

    // Revolut answers a deletion with no content at all, which reading it as json would take for a broken answer
    public function testAWebhookIsDeletedWithoutReadingABody(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $requested) use (&$url) {
            $url = $method . ' ' . $requested;

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->gateway($this->configService(['revolut-secret' => 'sk_live_1']), false, $client)->deleteWebhook('wh_1');

        $this->assertSame('DELETE https://merchant.revolut.com/api/webhooks/wh_1', $url);
    }

    // Revolut publishes no stable address for an order in its portal, and a link guessed at would send the shopkeeper nowhere
    public function testAnOrderLinksToNothing(): void
    {
        $this->assertNull($this->gateway($this->configService([]), false)->getTransactionUrl('ord_1'));
    }

    private function gateway(ConfigServiceInterface $configService, bool $testMode, ?HttpClientInterface $client = null): RevolutGateway
    {
        $mode = $this->createStub(PaymentTestModeInterface::class);
        $mode->method('isEnabled')->willReturn($testMode);

        return new RevolutGateway($configService, $client ?? new MockHttpClient(), $mode, new RevolutOrderReader());
    }

    private function checkoutRequest(): CheckoutRequest
    {
        return new CheckoutRequest(
            'eur',
            [
                ['name' => 'Livre', 'amount' => 1000, 'quantity' => 2],
                ['name' => 'Livraison', 'amount' => 500, 'quantity' => 1],
            ],
            'https://example.com/paid',
            'https://example.com/basket',
            'buyer@example.com',
            ['basket_id' => '42', 'order_number' => '202608-AB-12345'],
        );
    }

    private function webhookRequest(string $payload, string $secret, ?int $timestamp = null): Request
    {
        $timestamp = (string) ($timestamp ?? time() * 1000);
        $request = new Request([], [], [], [], [], [], $payload);
        $request->headers->set('revolut-request-timestamp', $timestamp);
        $request->headers->set('revolut-signature', 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $payload, $secret));

        return $request;
    }

    private function configService(array $values): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => $values[$slug] ?? null);

        return $configService;
    }
}
