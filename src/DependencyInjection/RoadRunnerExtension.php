<?php

namespace Rasskar\RoadRunnerBundle\DependencyInjection;

use Exception;
use Rasskar\RoadRunnerBundle\DependencyInjection\Configurators\KvConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Loads the bundle service definitions and applies the `road_runner:` configuration.
 * Each feature is wired by its own configurator (see {@see KvConfigurator}).
 */
final class RoadRunnerExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     * @param ContainerBuilder $container
     * @return void
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        new KvConfigurator()->configure($config['kv']['storages'], $container);
    }
}
