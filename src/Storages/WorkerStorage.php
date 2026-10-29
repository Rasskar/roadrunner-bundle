<?php

namespace Rasskar\RoadRunnerBundle\Storages;

use Rasskar\RoadRunnerBundle\Workers\Contracts\WorkerInterface;

/**
 * Resolves the worker for the current RR_MODE among `rr.worker`-tagged services.
 */
final readonly class WorkerStorage
{
    /**
     * @param iterable<WorkerInterface> $workers
     */
    public function __construct(
        private iterable $workers,
    ) {
    }

    /**
     * @param string $mode
     * @return WorkerInterface|null
     */
    public function getWorker(string $mode): WorkerInterface|null
    {
        foreach ($this->workers as $worker) {
            if ($worker->supports($mode)) {
                return $worker;
            }
        }

        return null;
    }
}