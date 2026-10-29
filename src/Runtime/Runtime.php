<?php

namespace Rasskar\RoadRunnerBundle\Runtime;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Symfony Runtime that switches to {@see Runner} when the process is started by RoadRunner (RR_MODE is set).
 * Enabled via `server.env: [APP_RUNTIME: Rasskar\RoadRunnerBundle\Runtime\Runtime]` in .rr.yaml;
 * otherwise (bin/console, php-fpm) it behaves exactly like {@see SymfonyRuntime}.
 */
final class Runtime extends SymfonyRuntime
{
    /**
     * @param object|null $application
     * @return RunnerInterface
     */
    public function getRunner(object|null $application): RunnerInterface
    {
        $mode = getenv('RR_MODE');

        if ($application instanceof KernelInterface && $mode !== false) {
            return new Runner($application, $mode);
        }

        return parent::getRunner($application);
    }
}