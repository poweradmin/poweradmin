<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

interface LimitedWorkerPool extends WorkerPool
{
    /**
     * Gets the maximum number of workers the pool may spawn to handle concurrent tasks.
     *
     * @return int The maximum number of workers.
     */
    public function getWorkerLimit(): int;
}
