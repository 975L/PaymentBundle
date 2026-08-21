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
    }

    public function prependExtension(ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets' => '@c975l/payment-bundle',
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
