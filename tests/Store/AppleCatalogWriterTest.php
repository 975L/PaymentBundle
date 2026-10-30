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
use c975L\PaymentBundle\Store\AppleCatalogWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// What App Store Connect is asked to write for one product
class AppleCatalogWriterTest extends TestCase
{
    // The answers bringing an existing product priced at 1,00 € up to date, before its screenshot
    private const array KNOWN_PRODUCT = [
        'GET /v1/apps/123/inAppPurchasesV2' => ['data' => [['id' => 'iap1']]],
        'GET /v2/inAppPurchases/iap1/inAppPurchaseLocalizations' => ['data' => []],
        'POST /v1/inAppPurchaseLocalizations' => ['data' => []],
        'GET /v2/inAppPurchases/iap1/pricePoints' => ['data' => [['id' => 'pp100', 'attributes' => ['customerPrice' => '1.00']]]],
        'POST /v1/inAppPurchasePriceSchedules' => ['data' => []],
    ];

    /** @var list<array{method: string, path: string, body: mixed}> */
    private array $calls = [];

    // A product Apple does not have yet is created, sold everywhere, named in each language and priced on the French price point matching the asked price
    public function testANewProductIsCreatedNamedAndPriced(): void
    {
        $this->writer([
            'GET /v1/apps/123/inAppPurchasesV2' => ['data' => []],
            'POST /v2/inAppPurchases' => ['data' => ['id' => 'iap1']],
            'GET /v1/territories' => ['data' => [['id' => 'FRA'], ['id' => 'USA']]],
            'POST /v1/inAppPurchaseAvailabilities' => ['data' => []],
            'GET /v2/inAppPurchases/iap1/inAppPurchaseLocalizations' => ['data' => [['id' => 'loc-fr', 'attributes' => ['locale' => 'fr-FR']]]],
            'PATCH /v1/inAppPurchaseLocalizations/loc-fr' => ['data' => []],
            'POST /v1/inAppPurchaseLocalizations' => ['data' => []],
            'GET /v2/inAppPurchases/iap1/pricePoints' => ['data' => [['id' => 'pp099', 'attributes' => ['customerPrice' => '0.99']], ['id' => 'pp100', 'attributes' => ['customerPrice' => '1.00']]]],
            'POST /v1/inAppPurchasePriceSchedules' => ['data' => []],
        ])->write(new InAppProduct('back.chateau_hurlton_orage', 100, ['fr' => 'Hurlton sous l\'orage, le dos animé', 'en' => 'Hurlton under the storm']));

        $byCall = array_column(array_map(static fn (array $call): array => [$call['method'] . ' ' . $call['path'], $call['body']], $this->calls), 1, 0);
        $this->assertSame('NON_CONSUMABLE', $byCall['POST /v2/inAppPurchases']['data']['attributes']['inAppPurchaseType']);
        $this->assertSame([['type' => 'territories', 'id' => 'FRA'], ['type' => 'territories', 'id' => 'USA']], $byCall['POST /v1/inAppPurchaseAvailabilities']['data']['relationships']['availableTerritories']['data']);
        $this->assertSame('Hurlton sous l\'orage, le dos a', $byCall['PATCH /v1/inAppPurchaseLocalizations/loc-fr']['data']['attributes']['name']);
        $this->assertSame('en-US', $byCall['POST /v1/inAppPurchaseLocalizations']['data']['attributes']['locale']);
        $this->assertSame('pp100', $byCall['POST /v1/inAppPurchasePriceSchedules']['included'][0]['relationships']['inAppPurchasePricePoint']['data']['id']);
    }

    // A product already there is only brought up to date, never created twice
    public function testAKnownProductIsNotCreatedAgain(): void
    {
        $this->writer(self::KNOWN_PRODUCT)->write(new InAppProduct('back.a', 100, ['fr' => 'A']));

        $this->assertNotContains('POST /v2/inAppPurchases', array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->calls));
    }

    public function testAPriceWithoutAPricePointIsReported(): void
    {
        $this->expectExceptionMessage('no price point at 1.50');

        $this->writer(self::KNOWN_PRODUCT)->write(new InAppProduct('back.a', 150, ['fr' => 'A']));
    }

    // A screenshot whose upload was interrupted is deleted and sent again, so the next run repairs what the last one left half done
    public function testAnInterruptedScreenshotIsUploadedAgain(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'shot');
        file_put_contents($path, 'png-bytes');
        $this->writer(self::KNOWN_PRODUCT + [
            'GET /v2/inAppPurchases/iap1/appStoreReviewScreenshot' => ['data' => ['id' => 'shot1', 'attributes' => ['assetDeliveryState' => ['state' => 'AWAITING_UPLOAD']]]],
            'DELETE /v1/inAppPurchaseAppStoreReviewScreenshots/shot1' => [],
            'POST /v1/inAppPurchaseAppStoreReviewScreenshots' => ['data' => ['id' => 'shot2', 'attributes' => ['uploadOperations' => [['method' => 'PUT', 'url' => 'https://upload.example/shot2', 'offset' => 0, 'length' => 9]]]]],
            'PUT /shot2' => [],
            'PATCH /v1/inAppPurchaseAppStoreReviewScreenshots/shot2' => ['data' => []],
        ])->write(new InAppProduct('back.a', 100, ['fr' => 'A'], screenshot: $path));
        unlink($path);

        $paths = array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->calls);
        $this->assertContains('DELETE /v1/inAppPurchaseAppStoreReviewScreenshots/shot1', $paths);
        $this->assertSame(md5('png-bytes'), end($this->calls)['body']['data']['attributes']['sourceFileChecksum']);
    }

    public function testAnUploadedScreenshotIsLeftAlone(): void
    {
        $this->writer(self::KNOWN_PRODUCT + [
            'GET /v2/inAppPurchases/iap1/appStoreReviewScreenshot' => ['data' => ['id' => 'shot1', 'attributes' => ['assetDeliveryState' => ['state' => 'COMPLETE']]]],
        ])->write(new InAppProduct('back.a', 100, ['fr' => 'A'], screenshot: '/nowhere.png'));

        $this->assertSame('GET /v2/inAppPurchases/iap1/appStoreReviewScreenshot', end($this->calls)['method'] . ' ' . end($this->calls)['path']);
    }

    // A wrong path is reported rather than sent to Apple as an empty file
    public function testAnUnreadableScreenshotIsReported(): void
    {
        $this->expectExceptionMessage('cannot be read');

        $this->writer(self::KNOWN_PRODUCT + [
            'GET /v2/inAppPurchases/iap1/appStoreReviewScreenshot' => ['data' => null],
        ])->write(new InAppProduct('back.a', 100, ['fr' => 'A'], screenshot: '/nowhere.png'));
    }

    /** @param array<string, array<string, mixed>> $answers keyed by "METHOD /path" */
    private function writer(array $answers): AppleCatalogWriter
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answers) {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => json_decode($options['body'] ?? 'null', true)];
            if (!isset($answers[$method . ' ' . $path])) {
                throw new \LogicException('Unexpected call ' . $method . ' ' . $path);
            }

            return new MockResponse((string) json_encode($answers[$method . ' ' . $path]));
        });
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $values = ['apple-app-id' => '123', 'apple-issuer-id' => 'issuer', 'apple-key-id' => 'K2', 'apple-private-key' => $pem];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);

        return new AppleCatalogWriter($configService, $client);
    }
}
