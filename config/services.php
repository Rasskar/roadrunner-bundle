<?php

use Doctrine\Persistence\ManagerRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use Rasskar\RoadRunnerBundle\Http\KernelTerminator;
use Rasskar\RoadRunnerBundle\Http\Middleware\DoctrineResilienceMiddleware;
use Rasskar\RoadRunnerBundle\Http\PipelineFactory;
use Rasskar\RoadRunnerBundle\Http\RequestHandler\SymfonyKernelRequestHandler;
use Rasskar\RoadRunnerBundle\Rpc\RPCFactory;
use Rasskar\RoadRunnerBundle\Storages\WorkerStorage;
use Rasskar\RoadRunnerBundle\Workers\HttpWorker;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker as RRWorker;
use Spiral\RoadRunner\WorkerInterface as RRWorkerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container) {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // RoadRunner protocol
    $services->set(Psr17Factory::class);

    $services->set(RRWorkerInterface::class)
        ->factory([RRWorker::class, 'create']);

    // chunkSize > 0: large bodies are sent in chunks instead of being loaded into memory
    $services->set(PSR7Worker::class)
        ->args([
            service(RRWorkerInterface::class),
            service(Psr17Factory::class),
            service(Psr17Factory::class),
            service(Psr17Factory::class),
        ])
        ->property('chunkSize', 8192);

    // PSR-7 <-> Symfony HttpFoundation bridge
    $services->set(HttpFoundationFactory::class);
    $services->set(PsrHttpFactory::class)
        ->args([
            service(Psr17Factory::class),
            service(Psr17Factory::class),
            service(Psr17Factory::class),
            service(Psr17Factory::class),
        ]);
    $services->alias(HttpMessageFactoryInterface::class, PsrHttpFactory::class);

    $services->set(KernelTerminator::class);
    $services->set(SymfonyKernelRequestHandler::class);

    // HTTP middleware pipeline (`rr.http_middleware`, registration order)
    if (interface_exists(ManagerRegistry::class)) {
        $services->set(DoctrineResilienceMiddleware::class)
            ->tag('rr.http_middleware');
    }

    $services->set(PipelineFactory::class)
        ->args([
            '$middlewares' => tagged_iterator('rr.http_middleware'),
            '$kernelHandler' => service(SymfonyKernelRequestHandler::class),
        ]);

    $services->set(RequestHandlerInterface::class)
        ->factory([service(PipelineFactory::class), 'build']);

    // Workers (`rr.worker`)
    $services->set(HttpWorker::class)->tag('rr.worker');

    $services->set(WorkerStorage::class)
        ->args(['$workers' => tagged_iterator('rr.worker')])
        ->public();

    // RoadRunner RPC
    $services->set(EnvironmentInterface::class)
        ->factory([Environment::class, 'fromGlobals']);

    $services->set(RPCInterface::class)
        ->factory([RPCFactory::class, 'fromEnvironment'])
        ->args([service(EnvironmentInterface::class)]);
};