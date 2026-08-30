<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Gateway;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Contract\VerifiableGatewayInterface;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Revolut ships no PHP SDK, so its Merchant API is called over the http client and nothing of its shapes leaves this class - the same bargain StripeGateway makes with Stripe\*. Which pair of keys it uses is decided here and nowhere else: nothing outside reads a key to guess whether the site charges for real (see PaymentTestMode)
class RevolutGateway implements ExpirableGatewayInterface, PaymentGatewayInterface, ReturnAwareGatewayInterface, VerifiableGatewayInterface
{
    public const string SLUG = 'revolut';

    // The Merchant API refuses a call that names no version and answers a different shape per version: what this class reads back is the shape of that date, so it is pinned here rather than left to whatever Revolut serves that day
    private const string API_VERSION = '2024-05-01';

    // The sandbox is a separate space with its own keys and its own orders, exactly as Stripe's test dashboard is
    private const string LIVE_API = 'https://merchant.revolut.com/api';

    private const string TEST_API = 'https://sandbox-merchant.revolut.com/api';

    // The five minutes Revolut asks a receiver to enforce: a signature stays valid for as long as the secret does, so replaying an old event is only stopped by refusing an old timestamp
    private const int TIMESTAMP_TOLERANCE = 300;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly PaymentTestModeInterface $testMode,
        private readonly RevolutOrderReader $orderReader,
    ) {
    }

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getSecret();
    }

    // Revolut charges one amount for the whole order where Stripe itemises it, so the lines are added up here and named in the description - what the customer reads on the checkout page is otherwise a bare total
    /** @return array{0: int, 1: string} */
    private function totalAndDescription(CheckoutRequest $request): array
    {
        $amount = 0;
        $names = [];

        foreach ($request->lines as $line) {
            $amount += $line['amount'] * $line['quantity'];
            $names[] = $line['quantity'] > 1 ? $line['quantity'] . ' x ' . $line['name'] : $line['name'];
        }

        return [$amount, mb_substr(implode(', ', $names), 0, 1000)];
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        [$amount, $description] = $this->totalAndDescription($request);

        $payload = [
            'amount' => $amount,
            'currency' => strtoupper($request->currency),
            'description' => $description,
            // The one thing of ours Revolut hands back with its events, under the name "merchant_order_ext_ref": the basket is written there because a notification carrying no basket settles nothing
            'merchant_order_data' => ['reference' => (string) ($request->metadata['basket_id'] ?? '')],
            // Where the customer is sent once they have paid. Revolut offers no counterpart for the customer who gives up, who is left on the checkout page with the site's own link out of it
            'redirect_url' => $request->successUrl,
        ];

        if (null !== $request->email && '' !== $request->email) {
            $payload['customer'] = ['email' => $request->email];
        }

        $order = $this->call('POST', '/orders', $payload);

        // An answer accepted by Revolut but naming no page to pay on would redirect the customer nowhere: the basket page says the shop cannot charge, rather than sending them to a blank url
        $checkoutUrl = $order['checkout_url'] ?? null;
        $orderId = $order['id'] ?? null;
        if (!is_string($checkoutUrl) || '' === $checkoutUrl || !is_string($orderId) || '' === $orderId) {
            throw new PaymentUnavailableException('Revolut opened no checkout for this order');
        }

        return new CheckoutSession($checkoutUrl, $orderId);
    }

    // An order stays payable at Revolut until it expires, and the customer editing their basket still has it open in a tab: cancelling it is what stops them paying for a basket that no longer exists
    public function expireCheckout(string $reference): void
    {
        $this->call('POST', '/orders/' . $reference . '/cancel');
    }

    // Only "ORDER_COMPLETED" says a basket is paid; every other event the webhook is subscribed to is authentic and of no interest here, hence a null rather than a refusal
    public function readNotification(Request $request): ?PaymentNotification
    {
        $payload = $request->getContent();
        $this->verifySignature($request, $payload);

        $event = json_decode($payload, true);
        if (!is_array($event) || 'ORDER_COMPLETED' !== ($event['event'] ?? null)) {
            return null;
        }

        // The event carries the order id and the merchant reference, and nothing of the state or the amount: the order itself is read back, so what settles a basket is what Revolut answers rather than what it posted
        $orderId = $event['order_id'] ?? null;
        if (!is_string($orderId) || '' === $orderId) {
            throw new InvalidNotificationException('Order id is missing from the event');
        }

        return $this->orderReader->read($this->call('GET', '/orders/' . $orderId));
    }

    // The customer coming back from Revolut, on a url the site chose before the order existed and which therefore carries nothing to look up: the order id is the reference kept on the payment, and the order is re-read from Revolut itself
    public function readReturn(Request $request, ?string $reference): ?PaymentNotification
    {
        if (null === $reference || '' === $reference) {
            return null;
        }

        return $this->orderReader->read($this->call('GET', '/orders/' . $reference));
    }

    // Revolut publishes no stable address for an order in its business portal, and a link guessed at would send the shopkeeper to a page that does not exist: the back-office shows the order id and links to nothing
    public function getTransactionUrl(string $transactionId): ?string
    {
        return null;
    }

    // Where the hosted checkout page lives, the sandbox host named alongside the live one: the redirection leaving the basket form lands there, and an extra form-action host authorises no navigation the site does not open itself
    public function getCheckoutDomains(): array
    {
        return ['checkout.revolut.com', 'sandbox-checkout.revolut.com'];
    }

    // Asks Revolut for a single order: the cheapest authenticated call the Merchant API offers, and the only one that tells a revoked or mistyped key from a well-formed one
    public function verifyCredentials(): ?string
    {
        if (!$this->isConfigured()) {
            return 'No secret key is set for the ' . ($this->testMode->isEnabled() ? 'test' : 'live') . ' mode';
        }

        try {
            $this->call('GET', '/orders?limit=1');
        } catch (HttpExceptionInterface $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Declares the site's webhook endpoint at Revolut and hands back the secret its events will be signed with.
     *
     * Revolut offers no screen for this, only this call, and it answers the secret once - which is why it is a
     * method here and a command on top of it, rather than a curl line in a README nobody runs twice the same way.
     * Called from c975l:payment:revolut:webhook only: it writes at the provider, and no web request may do that.
     *
     * @throws HttpExceptionInterface      when Revolut refuses the call or cannot be reached
     * @throws PaymentUnavailableException when Revolut accepts the call but answers no secret to check events with
     */
    public function registerWebhook(string $url): string
    {
        $webhook = $this->call('POST', '/webhooks', [
            'url' => $url,
            // The only event a site acts on; every other one would be authentic, read, and answered with a null
            'events' => ['ORDER_COMPLETED'],
        ]);

        $secret = $webhook['signing_secret'] ?? null;
        if (!is_string($secret) || '' === $secret) {
            throw new PaymentUnavailableException('Revolut declared the webhook but answered no signing secret');
        }

        return $secret;
    }

    /**
     * The webhooks already declared for the space in use.
     *
     * Revolut takes a second webhook on the same url rather than replacing the first, and then delivers every
     * event twice - one accepted on the secret the site stores, the other refused and retried three times. So
     * declaring one starts by reading what is already there.
     *
     * @return list<array{id?: string, url?: string, events?: list<string>}> as Revolut answers them
     *
     * @throws HttpExceptionInterface when Revolut refuses the call or cannot be reached
     */
    public function listWebhooks(): array
    {
        return array_values($this->call('GET', '/webhooks'));
    }

    // Stops a webhook being delivered to, which is the only way to take back a url declared twice: Revolut has no call that replaces one
    public function deleteWebhook(string $id): void
    {
        // Answered with no content, so the status is what is read - and reading it is also what makes the client send the request at all
        $this->send('DELETE', '/webhooks/' . $id)->getStatusCode();
    }

    // Refuses an event that is not Revolut's, an event replayed later than the tolerance, and an event arriving on a site holding no signing secret to check it with - a payload nobody signed is anybody's, and marking a basket paid on it would hand the order away
    private function verifySignature(Request $request, string $payload): void
    {
        $secret = $this->getWebhookSecret();
        $timestamp = (string) $request->headers->get('revolut-request-timestamp');
        $signatures = (string) $request->headers->get('revolut-signature');

        if ('' === $secret || '' === $timestamp || '' === $signatures) {
            throw new InvalidNotificationException('The event carries no signature to check');
        }

        // Revolut stamps its events in milliseconds
        if (abs(time() - intdiv((int) $timestamp, 1000)) > self::TIMESTAMP_TOLERANCE) {
            throw new InvalidNotificationException('The event is outside the accepted time window');
        }

        $expected = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $payload, $secret);

        // A secret being rotated has Revolut signing the same event with both, the one expiring and the new one, and either matching is enough
        foreach (explode(',', $signatures) as $signature) {
            if (hash_equals($expected, trim($signature))) {
                return;
            }
        }

        throw new InvalidNotificationException('The signature does not match the payload');
    }

    /**
     * Calls the Merchant API and returns what it answered.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws HttpExceptionInterface when Revolut refuses the call or cannot be reached
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        return $this->send($method, $path, $body)->toArray();
    }

    // The call itself, kept apart from decoding it: a deletion is answered with no content at all, which toArray() reads as a broken answer
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getSecret(),
                'Revolut-Api-Version' => self::API_VERSION,
                'Accept' => 'application/json',
            ],
        ];

        if (null !== $body) {
            $options['json'] = $body;
        }

        return $this->httpClient->request($method, $this->getApiUrl() . $path, $options);
    }

    private function getApiUrl(): string
    {
        return $this->testMode->isEnabled() ? self::TEST_API : self::LIVE_API;
    }

    private function getSecret(): string
    {
        return (string) $this->configService->get($this->testMode->isEnabled() ? 'revolut-secret-test' : 'revolut-secret');
    }

    private function getWebhookSecret(): string
    {
        return (string) $this->configService->get($this->testMode->isEnabled() ? 'revolut-webhook-secret-test' : 'revolut-webhook-secret');
    }
}
