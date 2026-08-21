<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Registry;

use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use PHPUnit\Framework\TestCase;

// How the basket reaches the bundle owning a line: every satellite registers its own kind, and nothing here knows what any of them sells
class BasketItemProviderRegistryTest extends TestCase
{
    // Nothing sells anything yet - the registry answers empty rather than raising on construction
    public function testAnEmptyRegistryHoldsNoKind(): void
    {
        $registry = new BasketItemProviderRegistry([]);

        $this->assertSame([], $registry->getKinds());
        $this->assertFalse($registry->has('product'));
    }

    // Providers are keyed on the kind they claim, which is what a basket line names
    public function testEveryProviderIsKeyedOnItsKind(): void
    {
        $product = $this->provider('product');
        $crowdfunding = $this->provider('crowdfunding');

        $registry = new BasketItemProviderRegistry([$product, $crowdfunding]);

        $this->assertSame(['product', 'crowdfunding'], $registry->getKinds());
        $this->assertTrue($registry->has('product'));
        $this->assertSame($product, $registry->get('product'));
        $this->assertSame($crowdfunding, $registry->get('crowdfunding'));
    }

    // A line naming a kind no installed bundle claims is a wiring mistake, and must say so rather than answer null
    public function testAnUnknownKindThrows(): void
    {
        $registry = new BasketItemProviderRegistry([$this->provider('product')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No BasketItemProviderInterface registered for kind "book"');

        $registry->get('book');
    }

    // Two bundles claiming the same kind is a collision the container cannot catch: the last registered wins, and the count says so
    public function testTheLastProviderOfAKindWins(): void
    {
        $first = $this->provider('product');
        $second = $this->provider('product');

        $registry = new BasketItemProviderRegistry([$first, $second]);

        $this->assertSame(['product'], $registry->getKinds());
        $this->assertSame($second, $registry->get('product'));
    }

    private function provider(string $kind): BasketItemProviderInterface
    {
        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('getKind')->willReturn($kind);

        return $provider;
    }
}
