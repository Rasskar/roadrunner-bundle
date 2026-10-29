<?php

namespace Rasskar\RoadRunnerBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Defers kernel.terminate until the response has been sent to the client,
 * as php-fpm does via fastcgi_finish_request() in Response::send().
 */
final class KernelTerminator
{
    /**
     * @var Request|null
     */
    private Request|null $request = null;

    /**
     * @var Response|null
     */
    private Response|null $response = null;

    /**
     * @param KernelInterface $kernel
     */
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function defer(Request $request, Response $response): void
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * @return void
     */
    public function terminate(): void
    {
        if ($this->request === null || $this->response === null) {
            return;
        }

        try {
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($this->request, $this->response);
            }
        } finally {
            $this->request = null;
            $this->response = null;
        }
    }
}