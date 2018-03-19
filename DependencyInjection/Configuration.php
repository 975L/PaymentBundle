<?php
/*
 * (c) 2017: 975l <contact@975l.com>
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
                ->scalarNode('returnRoute')
                ->end()
                ->scalarNode('defaultCurrency')
                    ->defaultValue('EUR')
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
