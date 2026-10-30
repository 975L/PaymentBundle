<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Store;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\InAppProduct;
use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Google Play, through the Android Publisher API and one service account doing it all - verifying, acknowledging, writing the catalogue - once it is invited on the app in the Play Console. Its notifications come by Pub/Sub push, the subscription's url carrying the token of google-play-notification-token, the payload itself being signed by nobody
class GooglePlayStore implements InAppStoreInterface
{
    use StoreConfigTrait;

    private const string API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';

    // Google's purchase states, only the first of which is a sale
    private const int PURCHASED = 0;

    private ?string $accessToken = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getSlug(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->config('google-play-package-name') && null !== $this->serviceAccount();
    }

    public function verifyPurchase(array $proof): ?InAppTransaction
    {
        $productId = $proof['productId'] ?? null;
        $token = $proof['purchaseToken'] ?? null;
        if (!\is_string($productId) || !\is_string($token) || '' === $token) {
            throw new InvalidNotificationException('No product id and purchase token to look up');
        }

        try {
            $response = $this->call('GET', 'purchases/products/' . rawurlencode($productId) . '/tokens/' . rawurlencode($token));
            if (\in_array($response->getStatusCode(), [400, 404, 410], true)) {
                throw new InvalidNotificationException('Google knows no such purchase');
            }
            $purchase = $response->toArray();
        } catch (HttpClientException $e) {
            throw $this->unreachable($e);
        }
        if (self::PURCHASED !== ($purchase['purchaseState'] ?? null)) {
            return null;
        }

        return new InAppTransaction(
            store: $this->getSlug(),
            productId: $productId,
            transactionId: self::transactionId($token),
            accountToken: $purchase['obfuscatedExternalAccountId'] ?? null,
            environment: 0 === ($purchase['purchaseType'] ?? null) ? 'sandbox' : 'production',
            purchaseToken: $token,
            acknowledged: 1 === ($purchase['acknowledgementState'] ?? null),
        );
    }

    public function acknowledge(InAppTransaction $transaction): void
    {
        if ($transaction->acknowledged || null === $transaction->purchaseToken) {
            return;
        }

        try {
            $this->call('POST', 'purchases/products/' . rawurlencode($transaction->productId) . '/tokens/' . rawurlencode($transaction->purchaseToken) . ':acknowledge')->getContent();
        } catch (HttpClientException $e) {
            throw $this->unreachable($e);
        }
    }

    // Only a voided one-off purchase is acted on, found back by its purchase token since the notification names no product
    public function readNotification(Request $request): ?InAppTransaction
    {
        $expected = $this->config('google-play-notification-token');
        if ('' === $expected || !hash_equals($expected, (string) $request->query->get('token'))) {
            throw new InvalidNotificationException('The notification does not carry the expected token');
        }

        $data = json_decode(base64_decode((string) (json_decode($request->getContent(), true)['message']['data'] ?? ''), true) ?: '', true);
        if (!\is_array($data) || ($data['packageName'] ?? null) !== $this->config('google-play-package-name')) {
            throw new InvalidNotificationException('The notification is not one of this app');
        }

        $voided = $data['voidedPurchaseNotification'] ?? null;
        if (!\is_array($voided) || !\is_string($voided['purchaseToken'] ?? null) || '' === $voided['purchaseToken']) {
            return null;
        }

        return new InAppTransaction(store: $this->getSlug(), productId: '', transactionId: self::transactionId($voided['purchaseToken']), event: PaymentEvent::Refunded, purchaseToken: $voided['purchaseToken']);
    }

    public function canPublish(): bool
    {
        return $this->isConfigured();
    }

    // Created or updated in one call, allowMissing doing the creation and Google converting the French price for the other countries
    public function publishProduct(InAppProduct $product): void
    {
        $listings = [];
        foreach ($product->names as $locale => $name) {
            if (isset(InAppProduct::STORE_LOCALES[$locale])) {
                $listings[InAppProduct::STORE_LOCALES[$locale]] = ['title' => mb_substr($name, 0, 55), 'description' => mb_substr($product->descriptions[$locale] ?? $name, 0, 200)];
            }
        }

        $this->call('PUT', 'inappproducts/' . $product->productId, [
            'query' => ['allowMissing' => 'true', 'autoConvertMissingPrices' => 'true'],
            'json' => [
                'packageName' => $this->config('google-play-package-name'),
                'sku' => $product->productId,
                'status' => 'active',
                'purchaseType' => 'managedUser',
                'defaultLanguage' => InAppProduct::STORE_LOCALES['fr'],
                'defaultPrice' => ['priceMicros' => (string) ($product->price * 10000), 'currency' => 'EUR'],
                'listings' => $listings,
            ],
        ])->toArray();
    }

    // The purchase token is what both a purchase and its voided notification always carry - an order id may be missing from either - hashed to fit the column
    private static function transactionId(string $purchaseToken): string
    {
        return 'token.' . sha1($purchaseToken);
    }

    // A service account not invited yet, a key revoked or Google down is the store being unavailable, not the purchase being false
    private function unreachable(HttpClientException $e): PaymentUnavailableException
    {
        return new PaymentUnavailableException('Google Play cannot be reached: ' . $e->getMessage(), previous: $e);
    }

    // A call to the Android Publisher API of the app, under the service account's access token
    /** @param array<string, mixed> $options */
    private function call(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->accessToken()];

        return $this->httpClient->request($method, self::API . rawurlencode($this->config('google-play-package-name')) . '/' . $path, $options);
    }

    // The service account's assertion traded for an access token, kept for the rest of the request
    private function accessToken(): string
    {
        if (null !== $this->accessToken) {
            return $this->accessToken;
        }

        $account = $this->serviceAccount() ?? throw new \RuntimeException('No Google Play service account is set');
        $assertion = Jwt::sign([], [
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => $account['token_uri'],
            'iat' => time(),
            'exp' => time() + 3600,
        ], $account['private_key']);

        return $this->accessToken = $this->httpClient->request('POST', $account['token_uri'], [
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
        ])->toArray()['access_token'];
    }

    // The JSON key file Google hands out for the service account, pasted whole into the setting
    /** @return array{client_email: string, private_key: string, token_uri: string}|null */
    private function serviceAccount(): ?array
    {
        $account = json_decode($this->config('google-play-service-account'), true);
        if (!\is_array($account) || !\is_string($account['client_email'] ?? null) || !\is_string($account['private_key'] ?? null)) {
            return null;
        }

        return ['client_email' => $account['client_email'], 'private_key' => $account['private_key'], 'token_uri' => \is_string($account['token_uri'] ?? null) ? $account['token_uri'] : 'https://oauth2.googleapis.com/token'];
    }
}
