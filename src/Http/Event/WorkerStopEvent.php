<?php

namespace Rasskar\RoadRunnerBundle\Http\Event;

/**
 * Dispatched once when a worker process leaves its request loop.
 */
final readonly class WorkerStopEvent
{
    /**
     * @var int
     */
    public int $pid;

    /**
     * @param bool $dueToError
     */
    public function __construct(
        public bool $dueToError,
    ) {
        $this->pid = getmypid();
    }
}
