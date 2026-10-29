<?php

namespace Rasskar\RoadRunnerBundle\Runtime;

use Rasskar\RoadRunnerBundle\Storages\WorkerStorage;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Boots the kernel once per RoadRunner worker process and hands control to the worker
 * for the current RR_MODE. Exit code 1 if no worker supports the mode.
 */
final class Runner implements RunnerInterface
{
    /**
     * @param KernelInterface $kernel
     * @param string $mode
     */
    public function __construct(
        private KernelInterface $kernel,
        private string $mode,
    ) {
    }

    /**
     * @return int
     */
    public function run(): int
    {
        $_SERVER['APP_RUNTIME_MODE'] = sprintf('web=%d&worker=1', $this->mode === Mode::MODE_HTTP ? 1 : 0);

        $this->kernel->boot();

        $worker = $this->kernel->getContainer()->get(WorkerStorage::class)->getWorker($this->mode);

        if ($worker === null) {
            error_log(sprintf('No RoadRunner worker registered for RR_MODE "%s".', $this->mode));

            return 1;
        }

        $worker->run();

        return 0;
    }
}