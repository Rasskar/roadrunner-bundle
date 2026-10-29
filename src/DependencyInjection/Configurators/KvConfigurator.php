<?php

namespace Rasskar\RoadRunnerBundle\DependencyInjection\Configurators;

use Rasskar\RoadRunnerBundle\Kv\KvCacheAdapter;
use Spiral\Goridge\RPC\RPCInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers a `cache.adapter.roadrunner.<storage>` service for each name in `road_runner.kv.storages`.
 */
final class KvConfigurator
{
    /**
     * @param string[] $storages
     * @param ContainerBuilder $container
     * @return void
     */
    public function configure(array $storages, ContainerBuilder $container): void
    {
        foreach ($storages as $storage) {
            $container->register('cache.adapter.roadrunner.' . $storage, KvCacheAdapter::class)
                ->setFactory([KvCacheAdapter::class, 'createConnection'])
                ->setArguments([
                    '',
                    [
                        'rpc' => new Reference(RPCInterface::class),
                        'storage' => $storage,
                    ],
                ]);
        }
    }
}
