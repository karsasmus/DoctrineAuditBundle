<?php

declare(strict_types=1);

/*
 * DoctrineAuditBundle
 */

namespace Kricha\DoctrineAuditBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ParentNodeDefinitionInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('kricha_doctrine_audit');
        // Keep compatibility with symfony/config < 4.2
        /** @var ParentNodeDefinitionInterface $rootNode */
        $rootNode = \method_exists($treeBuilder, 'getRootNode') ? $treeBuilder->getRootNode() : $treeBuilder->root(
            'kricha_doctrine_audit'
        );
        $rootNode
            ->children()
            ->scalarNode('changer_route')
            ->defaultValue('')
            ->end()
            ->scalarNode('table_prefix')
            ->defaultValue('')
            ->end()
            ->scalarNode('table_suffix')
            ->defaultValue('_audit')
            ->end()
            ->arrayNode('ignored_columns')
            ->canBeUnset()
            ->prototype('scalar')->end()
            ->end()
            ->arrayNode('entities')
            ->canBeUnset()
            ->prototype('array')
            ->children()
            ->arrayNode('ignored_columns')
            ->canBeUnset()
            ->prototype('scalar')->end()
            ->end()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
