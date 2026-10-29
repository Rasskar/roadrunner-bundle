<?php

namespace Rasskar\RoadRunnerBundle\Workers\Contracts;

/**
 * Blocking RoadRunner worker loop for one RR_MODE. Implementations must be tagged `rr.worker`.
 */
interface WorkerInterface
{
    /**
     * @return void
     */
    public function run(): void;

    /**
     * @param string $name
     * @return bool
     */
    public function supports(string $name): bool;
}