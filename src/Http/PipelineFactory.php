<?php

namespace Rasskar\RoadRunnerBundle\Http;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;

/**
 * Builds the PSR-15 pipeline: `rr.http_middleware`-tagged services in registration order
 * (first = outermost), with the Symfony kernel handler as the terminal handler.
 */
final readonly class PipelineFactory
{
    /**
     * @param iterable<MiddlewareInterface> $middlewares
     * @param RequestHandlerInterface $kernelHandler
     */
    public function __construct(
        private iterable $middlewares,
        private RequestHandlerInterface $kernelHandler,
    ) {
    }

    /**
     * @return RequestHandlerInterface
     */
    public function build(): RequestHandlerInterface
    {
        return new Relay([...$this->middlewares, $this->kernelHandler]);
    }
}