<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Registry;

use c975L\PaymentBundle\Contract\BasketDownloadProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use PHPUnit\Framework\TestCase;

// What the customer area renders under "my downloads" - gathered from every bundle selling files, this one never learning what a product is
class BasketDownloadRegistryTest extends TestCase
{
    // No bundle sells files: the section is left out rather than drawn empty
    public function testNothingIsOfferedWithoutAProvider(): void
    {
        $registry = new BasketDownloadRegistry([]);

        $this->assertFalse($registry->hasProviders());
        $this->assertSame([], $registry->getDownloads(new Basket()));
    }

    // Unlike recommendations, a basket can hold files of several kinds at once, so every provider is asked and their answers concatenated
    public function testEveryProviderContributes(): void
    {
        $registry = new BasketDownloadRegistry([
            $this->provider([['title' => 'A book', 'url' => '/download/a', 'size' => 1024]]),
            $this->provider([['title' => 'A track', 'url' => '/download/b', 'size' => null]]),
        ]);

        $downloads = $registry->getDownloads(new Basket());

        $this->assertTrue($registry->hasProviders());
        $this->assertCount(2, $downloads);
        $this->assertSame('A book', $downloads[0]['title']);
        $this->assertSame('A track', $downloads[1]['title']);
    }

    // A provider holding nothing of its kind for that basket says so with an empty list, and must not remove what the others found
    public function testAProviderWithNothingToOfferIsSkipped(): void
    {
        $registry = new BasketDownloadRegistry([
            $this->provider([]),
            $this->provider([['title' => 'A book', 'url' => '/download/a', 'size' => 1024]]),
        ]);

        $this->assertCount(1, $registry->getDownloads(new Basket()));
    }

    private function provider(array $downloads): BasketDownloadProviderInterface
    {
        $provider = $this->createStub(BasketDownloadProviderInterface::class);
        $provider->method('getDownloads')->willReturn($downloads);

        return $provider;
    }
}
