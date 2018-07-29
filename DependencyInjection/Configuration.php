<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('c975_l_payment');

        $rootNode
            ->children()
                ->scalarNode('live')
                    ->defaultFalse()
                ->end()
                ->scalarNode('site')
                ->end()
                ->scalarNode('defaultCurrency')
                    ->defaultValue('EUR')
                ->end()
                ->floatNode('vat')
                    ->defaultNull()
                ->end()
                ->floatNode('stripeFeePercentage')
                    ->defaultValue(1.4)
                ->end()
                ->integerNode('stripeFeeFixed')
                    ->defaultValue(25)
                ->end()
                ->scalarNode('timezone')
                    ->defaultNull()
                ->end()
                ->booleanNode('database')
                    ->defaultFalse()
                ->end()
                ->scalarNode('image')
                    ->defaultNull()
                ->end()
                ->booleanNode('zipCode')
                    ->defaultTrue()
                ->end()
                ->booleanNode('alipay')
                    ->defaultFalse()
                ->end()
                ->scalarNode('roleNeeded')
                    ->defaultValue('ROLE_ADMIN')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
