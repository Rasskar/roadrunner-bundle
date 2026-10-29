<?php

namespace Rasskar\RoadRunnerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for the `road_runner:` key (alias derived from {@see RoadRunnerExtension}).
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder('road_runner');

        $builder->getRootNode()
            ->info('https://github.com/rasskar/roadrunner-bundle')
            ->children()
                ->arrayNode('kv')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('storages')
                            ->info('RoadRunner KV storage names exposed as Symfony cache adapters; must match the `kv:` section of .rr.yaml.')
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $builder;
    }
}
