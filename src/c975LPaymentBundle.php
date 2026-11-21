<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle;

use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\PaymentBundle\Contract\BasketDownloadProviderInterface;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\BasketRecommendationProviderInterface;
use c975L\PaymentBundle\Contract\InAppProductProviderInterface;
use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LPaymentBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TaggedInterfacePass(BasketItemProviderInterface::class, 'payment.basket_item_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(BasketRecommendationProviderInterface::class, 'payment.basket_recommendation_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(BasketDownloadProviderInterface::class, 'payment.basket_download_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(PaymentGatewayInterface::class, 'payment.gateway'));
        $container->addCompilerPass(new TaggedInterfacePass(InAppStoreInterface::class, 'payment.in_app_store'));
        $container->addCompilerPass(new TaggedInterfacePass(InAppProductProviderInterface::class, 'payment.in_app_product_provider'));
    }

    public function prependExtension(ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        // Lifted under "test" as UiBundle's own limiters are, a functional suite driving its whole run from one address; hasParameter() guards a bare ContainerBuilder holding no kernel parameter
        $testEnvironment = $containerBuilder->hasParameter('kernel.environment') && 'test' === $containerBuilder->getParameter('kernel.environment');

        $containerBuilder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets' => '@c975l/payment-bundle',
                ],
            ],
            // The codes typed in the basket (see BasketController::applyCode()), per caller, declared here so no site has to: a gift card's code is money, and guessing one must not be free. An app declaring its own "payment_code" still decides, its config being merged over this one
            'rate_limiter' => [
                'payment_code' => [
                    'policy' => $testEnvironment ? 'no_limit' : 'sliding_window',
                    'limit' => 10,
                    'interval' => '15 minutes',
                ],
            ],
        ]);
    }

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerConfigurator->import('../config/services.yaml');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
