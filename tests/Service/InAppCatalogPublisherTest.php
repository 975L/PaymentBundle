<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Contract\InAppProduct;
use c975L\PaymentBundle\Contract\InAppProductProviderInterface;
use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Registry\InAppProductProviderRegistry;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Service\InAppCatalogPublisher;
use PHPUnit\Framework\TestCase;

// Every product into every store able to write it, one failure reported without stopping the rest
class InAppCatalogPublisherTest extends TestCase
{
    public function testEachProductGoesToEachStoreAbleToPublish(): void
    {
        $apple = $this->store('apple', canPublish: true, failsOn: 'back.b');
        $google = $this->store('google', canPublish: false);

        $report = new InAppCatalogPublisher(new InAppStoreRegistry([$apple, $google]), new InAppProductProviderRegistry([$this->provider()]))->publish();

        $this->assertSame([
            ['product' => 'back.a', 'store' => 'apple', 'error' => null],
            ['product' => 'back.b', 'store' => 'apple', 'error' => 'refused'],
        ], $report);
    }

    public function testOneProductCanBePublishedAlone(): void
    {
        $report = new InAppCatalogPublisher(new InAppStoreRegistry([$this->store('apple', canPublish: true)]), new InAppProductProviderRegistry([$this->provider()]))->publish('back.b');

        $this->assertSame(['back.b'], array_column($report, 'product'));
    }

    private function store(string $slug, bool $canPublish, ?string $failsOn = null): InAppStoreInterface
    {
        $store = $this->createStub(InAppStoreInterface::class);
        $store->method('getSlug')->willReturn($slug);
        $store->method('canPublish')->willReturn($canPublish);
        $store->method('publishProduct')->willReturnCallback(static function (InAppProduct $product) use ($failsOn): void {
            if ($product->productId === $failsOn) {
                throw new \RuntimeException('refused');
            }
        });

        return $store;
    }

    private function provider(): InAppProductProviderInterface
    {
        $provider = $this->createStub(InAppProductProviderInterface::class);
        $provider->method('getProducts')->willReturn([new InAppProduct('back.a', 100, ['fr' => 'A']), new InAppProduct('back.b', 200, ['fr' => 'B'])]);

        return $provider;
    }
}
