<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Registry;

use c975L\PaymentBundle\Contract\BasketRecommendationProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use PHPUnit\Framework\TestCase;

// The cross-sell strip under the basket - optional, and unlike the downloads it is one provider's answer rather than every provider's
class BasketRecommendationRegistryTest extends TestCase
{
    // No bundle recommends anything: the strip is left out rather than drawn empty
    public function testNothingIsRecommendedWithoutAProvider(): void
    {
        $registry = new BasketRecommendationRegistry([]);

        $this->assertSame([], $registry->getRecommendations(new Basket(), 4));
        $this->assertNull($registry->getTemplate());
    }

    // The one installed provider answers, and the limit reaches it untouched
    public function testTheProviderAnswersWithTheLimitItWasGiven(): void
    {
        $provider = $this->createMock(BasketRecommendationProviderInterface::class);
        $provider->expects($this->once())
            ->method('getRecommendations')
            ->with($this->isInstanceOf(Basket::class), 4)
            ->willReturn(['a-product']);

        $registry = new BasketRecommendationRegistry([$provider]);

        $this->assertSame(['a-product'], $registry->getRecommendations(new Basket(), 4));
    }

    // Only one strip fits under the basket, so the first registered wins and the others are never asked
    public function testOnlyTheFirstProviderIsAsked(): void
    {
        $first = $this->createStub(BasketRecommendationProviderInterface::class);
        $first->method('getRecommendations')->willReturn(['from-the-first']);

        $second = $this->createMock(BasketRecommendationProviderInterface::class);
        $second->expects($this->never())->method('getRecommendations');

        $registry = new BasketRecommendationRegistry([$first, $second]);

        $this->assertSame(['from-the-first'], $registry->getRecommendations(new Basket(), 4));
    }

    // The entries and the markup showing them come from the same provider, the basket page drawing nothing of its own
    public function testTheTemplateComesFromTheSameProvider(): void
    {
        $provider = $this->createStub(BasketRecommendationProviderInterface::class);
        $provider->method('getTemplate')->willReturn('@c975LShop/components/Product/Recommendations.html.twig');

        $registry = new BasketRecommendationRegistry([$provider]);

        $this->assertSame('@c975LShop/components/Product/Recommendations.html.twig', $registry->getTemplate());
    }
}
