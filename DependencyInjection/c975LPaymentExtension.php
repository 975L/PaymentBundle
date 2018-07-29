<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class c975LPaymentExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yml');

        $configuration = new Configuration();
        $processedConfig = $this->processConfiguration($configuration, $configs);

        $container->setParameter('c975_l_payment.live', $processedConfig['live']);
        $container->setParameter('c975_l_payment.site', $processedConfig['site']);
        $container->setParameter('c975_l_payment.defaultCurrency', strtoupper($processedConfig['defaultCurrency']));
        $container->setParameter('c975_l_payment.vat', $processedConfig['vat'] * 100);
        $container->setParameter('c975_l_payment.stripeFeePercentage', $processedConfig['stripeFeePercentage']);
        $container->setParameter('c975_l_payment.stripeFeeFixed', $processedConfig['stripeFeeFixed']);
        $container->setParameter('c975_l_payment.timezone', $processedConfig['timezone']);
        $container->setParameter('c975_l_payment.database', $processedConfig['database']);
        $container->setParameter('c975_l_payment.image', $processedConfig['image']);
        $container->setParameter('c975_l_payment.zipCode', $processedConfig['zipCode']);
        $container->setParameter('c975_l_payment.alipay', $processedConfig['alipay']);
        $container->setParameter('c975_l_payment.roleNeeded', $processedConfig['roleNeeded']);
    }
}
