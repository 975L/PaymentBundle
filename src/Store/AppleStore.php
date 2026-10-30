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

// Apple's App Store: a purchase is looked up by its transaction id through the App Store Server API, with the "In-App Purchase" key, and the catalogue is written through the App Store Connect API with the team key (see AppleCatalogWriter)
class AppleStore implements InAppStoreInterface
{
    use StoreConfigTrait;

    private const string PRODUCTION = 'https://api.storekit.itunes.apple.com';
    private const string SANDBOX = 'https://api.storekit-sandbox.itunes.apple.com';

    // The notifications taking back what was bought: a refund, and a family sharing access withdrawn
    private const array REVOKING = ['REFUND', 'REVOKE'];

    // A refund Apple reversed, the dispute won by the developer: what was taken back is given back
    private const string REFUND_REVERSED = 'REFUND_REVERSED';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly AppleJwsVerifier $verifier,
        private readonly AppleCatalogWriter $catalogWriter,
    ) {
    }

    public function getSlug(): string
    {
        return 'apple';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->config('apple-bundle-id') && '' !== $this->config('apple-issuer-id') && '' !== $this->config('apple-iap-key-id') && '' !== $this->config('apple-iap-private-key');
    }

    // Asked of production first, then of the sandbox on a 404: Apple's own review buys in the sandbox from the production app, so the mode cannot be told in advance (see payment-test-mode, which plays no part here)
    public function verifyPurchase(array $proof): ?InAppTransaction
    {
        $transactionId = $proof['transactionId'] ?? null;
        if (!\is_string($transactionId) || 1 !== preg_match('/^\d{1,32}$/', $transactionId)) {
            throw new InvalidNotificationException('No transaction id to look up');
        }

        try {
            foreach ([self::PRODUCTION, self::SANDBOX] as $host) {
                $response = $this->httpClient->request('GET', $host . '/inApps/v1/transactions/' . $transactionId, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                ]);
                if (404 !== $response->getStatusCode()) {
                    $signed = $response->toArray()['signedTransactionInfo'] ?? null;

                    return \is_string($signed) ? $this->transaction($this->verifier->verify($signed)) : throw new InvalidNotificationException('Apple returned no transaction');
                }
            }
        } catch (HttpClientException $e) {
            // A key revoked or Apple down is the store being unavailable, not the purchase being false
            throw new PaymentUnavailableException('Apple cannot be reached: ' . $e->getMessage(), previous: $e);
        }

        throw new InvalidNotificationException('Apple knows no such transaction');
    }

    public function acknowledge(InAppTransaction $transaction): void
    {
    }

    // App Store Server Notifications V2: one signed payload, a signed transaction inside it
    public function readNotification(Request $request): ?InAppTransaction
    {
        $signed = json_decode($request->getContent(), true)['signedPayload'] ?? null;
        if (!\is_string($signed)) {
            throw new InvalidNotificationException('No signed payload');
        }

        $notification = $this->verifier->verify($signed);
        $type = $notification['notificationType'] ?? null;
        $signedTransaction = $notification['data']['signedTransactionInfo'] ?? null;
        if (!\in_array($type, [...self::REVOKING, self::REFUND_REVERSED], true) || !\is_string($signedTransaction)) {
            return null;
        }

        return $this->transaction($this->verifier->verify($signedTransaction), self::REFUND_REVERSED === $type ? PaymentEvent::Paid : PaymentEvent::Refunded);
    }

    public function canPublish(): bool
    {
        return $this->catalogWriter->isConfigured();
    }

    public function publishProduct(InAppProduct $product): void
    {
        $this->catalogWriter->write($product);
    }

    // A transaction of another app, signed by Apple all the same, must not unlock anything here
    /** @param array<string, mixed> $info */
    private function transaction(array $info, PaymentEvent $event = PaymentEvent::Paid): InAppTransaction
    {
        if (($info['bundleId'] ?? null) !== $this->config('apple-bundle-id') || !\is_string($info['productId'] ?? null) || !isset($info['transactionId'])) {
            throw new InvalidNotificationException('The transaction is not one of this app');
        }

        return new InAppTransaction(
            store: $this->getSlug(),
            productId: $info['productId'],
            transactionId: (string) $info['transactionId'],
            accountToken: \is_string($info['appAccountToken'] ?? null) ? strtolower($info['appAccountToken']) : null,
            environment: strtolower((string) ($info['environment'] ?? 'production')),
            event: isset($info['revocationDate']) ? PaymentEvent::Refunded : $event,
        );
    }

    // The token of the App Store Server API, valid a few minutes, signed with the "In-App Purchase" key
    private function token(): string
    {
        return Jwt::sign(
            ['kid' => $this->config('apple-iap-key-id')],
            ['iss' => $this->config('apple-issuer-id'), 'iat' => time(), 'exp' => time() + 600, 'aud' => 'appstoreconnect-v1', 'bid' => $this->config('apple-bundle-id')],
            $this->config('apple-iap-private-key'),
        );
    }
}
