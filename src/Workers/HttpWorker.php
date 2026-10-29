<?php

namespace Rasskar\RoadRunnerBundle\Workers;

use JsonException;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Rasskar\RoadRunnerBundle\Http\Event\WorkerStartEvent;
use Rasskar\RoadRunnerBundle\Http\Event\WorkerStopEvent;
use Rasskar\RoadRunnerBundle\Http\KernelTerminator;
use Rasskar\RoadRunnerBundle\Workers\Contracts\WorkerInterface;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\Http\PSR7Worker;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * HTTP worker loop (RR_MODE=http).
 *
 * Per request: pipeline -> respond -> kernel.terminate -> services reset. Failures outside the
 * kernel are reported to RoadRunner and stop the worker; terminate failures are only logged.
 */
final readonly class HttpWorker implements WorkerInterface
{
    /**
     * @var ResetInterface|null
     */
    private ResetInterface|null $resetter;

    /**
     * @param PSR7Worker $worker
     * @param RequestHandlerInterface $pipeline
     * @param KernelTerminator $terminator
     * @param EventDispatcherInterface $events
     * @param LoggerInterface $logger
     * @param KernelInterface $kernel
     */
    public function __construct(
        private PSR7Worker $worker,
        private RequestHandlerInterface $pipeline,
        private KernelTerminator $terminator,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        KernelInterface $kernel,
    ) {
        $container = $kernel->getContainer();
        $this->resetter = $container->has('services_resetter')
            ? $container->get('services_resetter')
            : null;
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function run(): void
    {
        $this->events->dispatch(new WorkerStartEvent());

        $stoppedDueToError = false;

        while ($request = $this->worker->waitRequest(populateServer: false)) {
            try {
                $response = $this->pipeline->handle($request);
                $this->worker->respond($response);
            } catch (Throwable $e) {
                $this->worker->getWorker()->error((string) $e);
                $this->worker->getWorker()->stop();
                $stoppedDueToError = true;
            } finally {
                $this->finishRequest();
            }
        }

        $this->events->dispatch(new WorkerStopEvent(dueToError: $stoppedDueToError));
    }

    /**
     * @param string $name
     * @return bool
     */
    public function supports(string $name): bool
    {
        return $name === Mode::MODE_HTTP;
    }

    /**
     * @return void
     */
    private function finishRequest(): void
    {
        try {
            $this->terminator->terminate();
        } catch (Throwable $e) {
            $this->logger->error('kernel.terminate listener failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        } finally {
            $this->resetter?->reset();
        }
    }
}