<?php

namespace Rasskar\RoadRunnerBundle\Http\Event;

/**
 * Dispatched once when a worker process enters its request loop.
 */
final readonly class WorkerStartEvent
{
    /**
     * @var int
     */
    public int $pid;

    public function __construct()
    {
        $this->pid = getmypid();
    }
}
