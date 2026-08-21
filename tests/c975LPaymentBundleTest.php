<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests;

use c975L\PaymentBundle\c975LPaymentBundle;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\BasketRecommendationProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class c975LPaymentBundleTest extends TestCase
{
    // Regression: extra positional args to prependExtensionConfig() were silently ignored by PHP
    public function testPrependExtensionRegistersAssetMapperPathForFramework(): void
    {
        $container = new ContainerBuilder();
        $configurator = $this->createStub(ContainerConfigurator::class);

        new c975LPaymentBundle()->prependExtension($configurator, $container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        $this->assertNotEmpty($frameworkConfigs, 'The framework extension must receive a prepended config');

        $paths = $frameworkConfigs[0]['asset_mapper']['paths'] ?? [];
        $this->assertCount(1, $paths);
        $this->assertSame(realpath(\dirname(__DIR__) . '/assets'), realpath(array_key_first($paths)));
        $this->assertSame('@c975l/payment-bundle', reset($paths));
    }

    public function testBuildRegistersACompilerPassTaggingBasketItemProviders(): void
    {
        $container = new ContainerBuilder();
        $container->register('basket_item_provider', c975LPaymentBundleTestBasketItemProviderFixture::class);
        $container->register('basket_recommendation_provider', c975LPaymentBundleTestBasketRecommendationProviderFixture::class);

        new c975LPaymentBundle()->build($container);

        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            $pass->process($container);
        }

        $this->assertTrue($container->getDefinition('basket_item_provider')->hasTag('payment.basket_item_provider'));
        $this->assertTrue($container->getDefinition('basket_recommendation_provider')->hasTag('payment.basket_recommendation_provider'));
    }

    public function testLoadExtensionImportsServicesYaml(): void
    {
        $container = new ContainerBuilder();

        new c975LPaymentBundle()->getContainerExtension()->load([], $container);

        $this->assertTrue($container->hasDefinition(BasketServiceInterface::class) || $container->hasAlias(BasketServiceInterface::class));
    }

    public function testGetPathReturnsTheBundleRootDirectory(): void
    {
        $bundle = new c975LPaymentBundle();

        $this->assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}

class c975LPaymentBundleTestBasketItemProviderFixture implements BasketItemProviderInterface
{
    public function getKind(): string
    {
        return 'test';
    }

    public function findItem(int | string $id): ?object
    {
        return null;
    }

    public function validateAddition(object $item, int $quantity): ?string
    {
        return null;
    }

    public function validateCheckout(Basket $basket, array $itemsOfThisKind): ?string
    {
        return null;
    }

    public function toBasketData(object $item, int $quantity): array
    {
        return [];
    }

    public function getContentFlags(array $itemData): int
    {
        return 0;
    }

    public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array
    {
        return [];
    }

    public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void
    {
    }
}

class c975LPaymentBundleTestBasketRecommendationProviderFixture implements BasketRecommendationProviderInterface
{
    public function getRecommendations(Basket $basket, int $limit): array
    {
        return [];
    }
}
