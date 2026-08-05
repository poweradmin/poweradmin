<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Revolt\EventLoop;

/**
 * Gets or sets the global worker pool.
 *
 * @param WorkerPool|null $pool A worker pool instance.
 *
 * @return WorkerPool The global worker pool instance.
 */
function workerPool(?WorkerPool $pool = null): WorkerPool
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($pool) {
        return $map[$driver] = $pool;
    }

    return $map[$driver] ??= new ContextWorkerPool();
}

/**
 * @template TResult
 * @template TReceive
 * @template TSend
 *
 * Executes a {@see Task} on the global worker pool.
 *
 * @param Task<TResult, TReceive, TSend> $task The task to execute.
 * @param Cancellation|null $cancellation Token to request cancellation. The task must support cancellation for
 * this to have any effect.
 *
 * @return Execution<TResult, TReceive, TSend>
 */
function submit(Task $task, ?Cancellation $cancellation = null): Execution
{
    return workerPool()->submit($task, $cancellation);
}

/**
 * Gets an available worker from the global worker pool.
 */
function getWorker(): Worker
{
    return workerPool()->getWorker();
}

/**
 * Creates a worker using the global worker factory.
 */
function createWorker(): Worker
{
    return workerFactory()->create();
}

/**
 * Gets or sets the global worker factory.
 */
function workerFactory(?WorkerFactory $factory = null): WorkerFactory
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($factory) {
        return $map[$driver] = $factory;
    }

    return $map[$driver] ??= new ContextWorkerFactory();
}
