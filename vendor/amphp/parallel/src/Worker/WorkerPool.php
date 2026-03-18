<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Parallel\Context\StatusError;

/**
 * An interface for worker pools.
 */
interface WorkerPool extends Worker
{
    /** @var int The default maximum pool size. */
    public const DEFAULT_WORKER_LIMIT = 32;

    /**
     * Gets a worker from the pool. The worker is marked as busy and will only be reused if the pool runs out of
     * idle workers. The worker will be automatically marked as idle once no references to the returned worker remain.
     *
     * This method does not guarantee the worker will be dedicated to a particular task, rather is designed if you
     * wish to send a series of tasks to a single worker. For a dedicated worker, create a new worker using a
     * {@see WorkerFactory} or {@see createWorker()}.
     *
     * @throws StatusError If the pool is not running.
     */
    public function getWorker(): Worker;

    /**
     * Gets the number of workers currently running in the pool.
     *
     * @return int The number of workers.
     */
    public function getWorkerCount(): int;

    /**
     * Gets the number of workers that are currently idle.
     *
     * @return int The number of idle workers.
     */
    public function getIdleWorkerCount(): int;
}
