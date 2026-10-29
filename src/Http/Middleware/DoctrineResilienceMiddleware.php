<?php

namespace Rasskar\RoadRunnerBundle\Http\Middleware;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps Doctrine usable across requests in a long-running worker: closes dead DBAL connections
 * before the request (Doctrine reconnects lazily) and resets EntityManagers closed by a failure.
 * Registered only when doctrine/persistence is installed.
 */
final readonly class DoctrineResilienceMiddleware implements MiddlewareInterface
{
    /**
     * @param ManagerRegistry $registry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->pingConnections();

        try {
            return $handler->handle($request);
        } finally {
            $this->recoverClosedManagers();
        }
    }

    /**
     * @return void
     */
    private function pingConnections(): void
    {
        foreach ($this->registry->getConnections() as $connection) {
            if (!$connection->isConnected()) {
                continue;
            }

            try {
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            } catch (Throwable) {
                $connection->close();
            }
        }
    }

    /**
     * @return void
     */
    private function recoverClosedManagers(): void
    {
        foreach ($this->registry->getManagers() as $name => $manager) {
            if (!$manager->isOpen()) {
                $this->registry->resetManager($name);

                $this->logger->warning('Doctrine EntityManager was closed and has been reset.', [
                    'manager' => $name,
                ]);
            }
        }
    }
}