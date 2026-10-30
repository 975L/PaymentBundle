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
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Writes a product into App Store Connect with the team key (the one EAS builds with): created once with its availability everywhere, then on every run its names and descriptions, its price for France, and its review screenshot until Apple holds a whole one. Apple caps a store name at 30 characters and a description at 45, so both are cut there
class AppleCatalogWriter
{
    use StoreConfigTrait;

    private const string API = 'https://api.appstoreconnect.apple.com';

    // The states of a screenshot whose bytes reached Apple, processed or being so; any other is an upload left half done
    private const array UPLOADED = ['UPLOAD_COMPLETE', 'COMPLETE'];

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // Whether the team key and the app are set, which are not the keys verifying a purchase
    public function isConfigured(): bool
    {
        return '' !== $this->config('apple-app-id') && '' !== $this->config('apple-issuer-id') && '' !== $this->config('apple-key-id') && '' !== $this->config('apple-private-key');
    }

    // Creates the product if Apple does not have it, then brings everything else up to date
    public function write(InAppProduct $product): void
    {
        $id = $this->find($product->productId) ?? $this->create($product);
        $this->writeLocalizations($id, $product);
        $this->writePrice($id, $product->price);
        if (null !== $product->screenshot) {
            $this->writeScreenshot($id, $product->screenshot);
        }
    }

    // Apple's id of the product, null when it does not exist there yet
    private function find(string $productId): ?string
    {
        $found = $this->call('GET', '/v1/apps/' . $this->config('apple-app-id') . '/inAppPurchasesV2', query: ['filter[productId]' => $productId, 'limit' => 1]);

        return $found['data'][0]['id'] ?? null;
    }

    // A new product, sold in every territory and in those Apple opens later
    private function create(InAppProduct $product): string
    {
        $id = $this->call('POST', '/v2/inAppPurchases', ['data' => [
            'type' => 'inAppPurchases',
            'attributes' => ['name' => mb_substr($product->names['fr'] ?? $product->productId, 0, 64), 'productId' => $product->productId, 'inAppPurchaseType' => 'NON_CONSUMABLE'],
            'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => $this->config('apple-app-id')]]],
        ]])['data']['id'];

        $territories = array_map(static fn (array $territory): array => ['type' => 'territories', 'id' => $territory['id']], $this->call('GET', '/v1/territories', query: ['limit' => 200])['data']);
        $this->call('POST', '/v1/inAppPurchaseAvailabilities', ['data' => [
            'type' => 'inAppPurchaseAvailabilities',
            'attributes' => ['availableInNewTerritories' => true],
            'relationships' => ['inAppPurchase' => ['data' => ['type' => 'inAppPurchases', 'id' => $id]], 'availableTerritories' => ['data' => $territories]],
        ]]);

        return $id;
    }

    // The name and description of each language the stores know, updated where Apple has one, created where it does not
    private function writeLocalizations(string $id, InAppProduct $product): void
    {
        $existing = [];
        foreach ($this->call('GET', '/v2/inAppPurchases/' . $id . '/inAppPurchaseLocalizations', query: ['limit' => 50])['data'] as $localization) {
            $existing[$localization['attributes']['locale']] = $localization['id'];
        }

        foreach ($product->names as $locale => $name) {
            $storeLocale = InAppProduct::STORE_LOCALES[$locale] ?? null;
            if (null === $storeLocale) {
                continue;
            }
            $attributes = ['name' => mb_substr($name, 0, 30), 'description' => mb_substr($product->descriptions[$locale] ?? $name, 0, 45)];
            if (isset($existing[$storeLocale])) {
                $this->call('PATCH', '/v1/inAppPurchaseLocalizations/' . $existing[$storeLocale], ['data' => ['type' => 'inAppPurchaseLocalizations', 'id' => $existing[$storeLocale], 'attributes' => $attributes]]);
                continue;
            }
            $this->call('POST', '/v1/inAppPurchaseLocalizations', ['data' => [
                'type' => 'inAppPurchaseLocalizations',
                'attributes' => $attributes + ['locale' => $storeLocale],
                'relationships' => ['inAppPurchaseV2' => ['data' => ['type' => 'inAppPurchases', 'id' => $id]]],
            ]]);
        }
    }

    // The price is one of Apple's price points for France, the one whose customer price is exactly the asked one - 1,00 € is one of them - Apple deriving every other territory from it
    private function writePrice(string $id, int $price): void
    {
        $points = $this->call('GET', '/v2/inAppPurchases/' . $id . '/pricePoints', query: ['filter[territory]' => 'FRA', 'limit' => 8000])['data'];
        $point = array_find($points, static fn (array $candidate): bool => (int) round((float) $candidate['attributes']['customerPrice'] * 100) === $price);
        if (null === $point) {
            throw new \RuntimeException(sprintf('Apple has no price point at %.2f € for France', $price / 100));
        }

        $this->call('POST', '/v1/inAppPurchasePriceSchedules', [
            'data' => [
                'type' => 'inAppPurchasePriceSchedules',
                'relationships' => [
                    'inAppPurchase' => ['data' => ['type' => 'inAppPurchases', 'id' => $id]],
                    'baseTerritory' => ['data' => ['type' => 'territories', 'id' => 'FRA']],
                    'manualPrices' => ['data' => [['type' => 'inAppPurchasePrices', 'id' => '${price}']]],
                ],
            ],
            'included' => [[
                'type' => 'inAppPurchasePrices',
                'id' => '${price}',
                'attributes' => ['startDate' => null],
                'relationships' => ['inAppPurchasePricePoint' => ['data' => ['type' => 'inAppPurchasePricePoints', 'id' => $point['id']]]],
            ]],
        ]);
    }

    // A screenshot already uploaded is left alone; one whose upload was interrupted is deleted and sent again, so a run left half done is repaired by the next
    private function writeScreenshot(string $id, string $path): void
    {
        $existing = $this->call('GET', '/v2/inAppPurchases/' . $id . '/appStoreReviewScreenshot')['data'] ?? null;
        if (\in_array($existing['attributes']['assetDeliveryState']['state'] ?? null, self::UPLOADED, true)) {
            return;
        }
        if (isset($existing['id'])) {
            $this->call('DELETE', '/v1/inAppPurchaseAppStoreReviewScreenshots/' . $existing['id']);
        }

        $this->uploadScreenshot($id, $path);
    }

    // Apple's upload in three moves: a reservation telling where to send the bytes, the bytes, then the reservation marked uploaded with their checksum
    private function uploadScreenshot(string $id, string $path): void
    {
        $bytes = is_readable($path) ? file_get_contents($path) : false;
        if (false === $bytes || '' === $bytes) {
            throw new \RuntimeException(sprintf('The screenshot "%s" cannot be read', $path));
        }
        $reservation = $this->call('POST', '/v1/inAppPurchaseAppStoreReviewScreenshots', ['data' => [
            'type' => 'inAppPurchaseAppStoreReviewScreenshots',
            'attributes' => ['fileSize' => \strlen($bytes), 'fileName' => basename($path)],
            'relationships' => ['inAppPurchaseV2' => ['data' => ['type' => 'inAppPurchases', 'id' => $id]]],
        ]])['data'];

        foreach ($reservation['attributes']['uploadOperations'] as $operation) {
            $headers = [];
            foreach ($operation['requestHeaders'] ?? [] as $header) {
                $headers[$header['name']] = $header['value'];
            }
            $this->httpClient->request($operation['method'], $operation['url'], ['headers' => $headers, 'body' => substr($bytes, $operation['offset'], $operation['length'])])->getContent();
        }

        $this->call('PATCH', '/v1/inAppPurchaseAppStoreReviewScreenshots/' . $reservation['id'], ['data' => [
            'type' => 'inAppPurchaseAppStoreReviewScreenshots',
            'id' => $reservation['id'],
            'attributes' => ['uploaded' => true, 'sourceFileChecksum' => md5($bytes)],
        ]]);
    }

    // A call to the App Store Connect API under the team token, its JSON answer decoded
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->token()], 'query' => $query];
        if (null !== $body) {
            $options['json'] = $body;
        }
        $response = $this->httpClient->request($method, self::API . $path, $options);

        return 204 === $response->getStatusCode() ? [] : $response->toArray();
    }

    // The App Store Connect token, team key, twenty minutes at most
    private function token(): string
    {
        return Jwt::sign(
            ['kid' => $this->config('apple-key-id')],
            ['iss' => $this->config('apple-issuer-id'), 'iat' => time(), 'exp' => time() + 1200, 'aud' => 'appstoreconnect-v1'],
            $this->config('apple-private-key'),
        );
    }
}
