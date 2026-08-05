<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;

/**
 * An interface for a parallel worker thread that runs a queue of tasks.
 */
interface Worker
{
    /**
     * Checks if the worker is running.
     *
     * @return bool True if the worker is running, otherwise false.
     */
    public function isRunning(): bool;

    /**
     * Checks if the worker is currently idle.
     */
    public function isIdle(): bool;

    /**
     * @template TResult
     * @template TReceive
     * @template TSend
     *
     * Executes a {@see Task} on the worker.
     *
     * @param Task<TResult, TReceive, TSend> $task The task to execute.
     * @param Cancellation|null $cancellation Token to request cancellation. The task must support cancellation for
     * this to have any effect.
     *
     * @return Execution<TResult, TReceive, TSend>
     */
    public function submit(Task $task, ?Cancellation $cancellation = null): Execution;

    /**
     * Gracefully shutdown the worker once all outstanding tasks have completed executing. Returns once the
     * worker has been shutdown.
     */
    public function shutdown(): void;

    /**
     * Immediately kills the context.
     */
    public function kill(): void;
}
