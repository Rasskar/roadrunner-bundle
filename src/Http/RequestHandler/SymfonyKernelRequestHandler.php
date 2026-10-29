<?php

namespace Rasskar\RoadRunnerBundle\Http\RequestHandler;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasskar\RoadRunnerBundle\Http\KernelTerminator;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Terminal PSR-15 handler: converts the PSR-7 request to Symfony, runs HttpKernel, prepares the
 * response and converts it back. kernel.terminate is deferred to the worker via {@see KernelTerminator}.
 */
final readonly class SymfonyKernelRequestHandler implements RequestHandlerInterface
{
    /**
     * @param KernelInterface $kernel
     * @param HttpFoundationFactory $httpFoundationFactory
     * @param HttpMessageFactoryInterface $psrHttpFactory
     * @param KernelTerminator $terminator
     */
    public function __construct(
        private KernelInterface $kernel,
        private HttpFoundationFactory $httpFoundationFactory,
        private HttpMessageFactoryInterface $psrHttpFactory,
        private KernelTerminator $terminator,
    ) {
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $symfonyRequest = $this->httpFoundationFactory->createRequest($request);
        $symfonyResponse = $this->kernel->handle($symfonyRequest);
        $symfonyResponse->prepare($symfonyRequest);

        $this->terminator->defer($symfonyRequest, $symfonyResponse);

        return $this->psrHttpFactory->createResponse($symfonyResponse);
    }
}