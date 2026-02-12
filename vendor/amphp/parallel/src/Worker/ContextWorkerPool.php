<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\StatusError;
use Revolt\EventLoop;
use function Amp\async;

/**
 * Provides a pool of workers that can be used to execute multiple tasks asynchronously.
 *
 * A worker pool is a collection of worker threads that can perform multiple
 * tasks simultaneously. The load on each worker is balanced such that tasks
 * are completed as soon as possible and workers are used efficiently.
 */
final class ContextWorkerPool implements LimitedWorkerPool
{
    use ForbidCloning;
    use ForbidSerialization;

    private int $pendingWorkerCount = 0;

    /** @var \SplObjectStorage<Worker, int> A collection of all workers in the pool. */
    private readonly \SplObjectStorage $workers;

    /** @var \SplQueue<Worker> A collection of idle workers. */
    private readonly \SplQueue $idleWorkers;

    /** @var \SplQueue<DeferredFuture<Worker|null>> Task submissions awaiting an available worker. */
    private readonly \SplQueue $waiting;

    /** @var \Closure(Worker):void */
    private readonly \Closure $push;

    private ?Future $exitStatus = null;

    private readonly DeferredCancellation $deferredCancellation;

    /**
     * Creates a new worker pool.
     *
     * @param int $limit The maximum number of workers the pool should spawn.
     *     Defaults to `Pool::DEFAULT_MAX_SIZE`.
     * @param WorkerFactory|null $factory A worker factory to be used to create
     *     new workers.
     *
     * @throws \Error
     */
    public function __construct(
        private readonly int $limit = self::DEFAULT_WORKER_LIMIT,
        private readonly ?WorkerFactory $factory = null,
    ) {
        if ($limit <= 0) {
            throw new \ValueError("Maximum size must be a positive integer");
        }

        $this->workers = new \SplObjectStorage();
        $this->idleWorkers = $idleWorkers = new \SplQueue();
        $this->waiting = $waiting = new \SplQueue();

        $this->deferredCancellation = new DeferredCancellation();

        $this->push = static function (Worker $worker) use ($waiting, $idleWorkers): void {
            if ($waiting->isEmpty()) {
                $idleWorkers->push($worker);
            } else {
                $waiting->dequeue()->complete($worker);
            }
        };
    }

    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->deferredCancellation->cancel();
            self::killWorkers($this->workers, $this->waiting);
        }
    }

    /**
     * Checks if the pool is running.
     *
     * @return bool True if the pool is running, otherwise false.
     */
    public function isRunning(): bool
    {
        return !$this->deferredCancellation->isCancelled();
    }

    /**
     * Checks if the pool has any idle workers.
     *
     * @return bool True if the pool has at least one idle worker, otherwise false.
     */
    public function isIdle(): bool
    {
        return $this->idleWorkers->count() > 0 || $this->workers->count() < $this->limit;
    }

    public function getWorkerLimit(): int
    {
        return $this->limit;
    }

    /**
     * Gets the maximum number of workers the pool may spawn to handle concurrent tasks.
     *
     * @return int The maximum number of workers.
     *
     * @deprecated Use {@see getWorkerLimit()} instead.
     */
    public function getLimit(): int
    {
        return $this->getWorkerLimit();
    }

    public function getWorkerCount(): int
    {
        return $this->workers->count() + $this->pendingWorkerCount;
    }

    public function getIdleWorkerCount(): int
    {
        return $this->idleWorkers->count();
    }

    /**
     * Submits a {@see Task} to be executed by the worker pool.
     */
    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        $worker = $this->pull();
        $push = $this->push;

        try {
            $execution = $worker->submit($task, $cancellation);
        } catch (\Throwable $exception) {
            $push($worker);
            throw $exception;
        }

        $execution->getFuture()->finally(static fn () => $push($worker))->ignore();

        return $execution;
    }

    /**
     * Shuts down the pool and all workers in it.
     *
     * @throws StatusError If the pool has not been started.
     */
    public function shutdown(): void
    {
        if ($this->exitStatus) {
            $this->exitStatus->await();
            return;
        }

        $this->deferredCancellation->cancel();

        while (!$this->waiting->isEmpty()) {
            $this->waiting->dequeue()->error(
                $exception ??= new WorkerException('The pool shut down before the task could be executed'),
            );
        }

        $futures = \array_map(
            static fn (Worker $worker) => async($worker->shutdown(...)),
            \iterator_to_array($this->workers),
        );

        ($this->exitStatus = async(Future\awaitAll(...), $futures)->map(static fn () => null))->await();
    }

    /**
     * Kills all workers in the pool and halts the worker pool.
     */
    public function kill(): void
    {
        $this->deferredCancellation->cancel();
        self::killWorkers($this->workers, $this->waiting);
    }

    /**
     * @param \SplObjectStorage<Worker, int> $workers
     * @param \SplQueue<DeferredFuture<Worker|null>> $waiting
     */
    private static function killWorkers(
        \SplObjectStorage $workers,
        \SplQueue $waiting,
        ?\Throwable $exception = null,
    ): void {
        foreach ($workers as $worker) {
            \assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $worker->kill();
            }
        }

        while (!$waiting->isEmpty()) {
            $waiting->dequeue()->error(
                $exception ??= new WorkerException('The pool was killed before the task could be executed'),
            );
        }
    }

    public function getWorker(): Worker
    {
        $worker = $this->pull();
        return new Internal\PooledWorker($worker, $this->push);
    }

    /**
     * Pulls a worker from the pool.
     *
     * @throws StatusError
     * @throws WorkerException
     */
    private function pull(): Worker
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shut down");
        }

        do {
            if ($this->idleWorkers->isEmpty()) {
                /** @var DeferredFuture<Worker|null> $deferredFuture */
                $deferredFuture = new DeferredFuture;
                $this->waiting->enqueue($deferredFuture);

                if ($this->getWorkerCount() < $this->limit) {
                    // Max worker count has not been reached, so create another worker.
                    $this->pendingWorkerCount++;

                    $factory = $this->factory ?? workerFactory();
                    $pending = &$this->pendingWorkerCount;
                    $cancellation = $this->deferredCancellation->getCancellation();
                    $workers = $this->workers;
                    $future = async(static function () use (&$pending, $factory, $workers, $cancellation): Worker {
                        try {
                            $worker = $factory->create($cancellation);
                        } catch (CancelledException) {
                            throw new WorkerException('The pool shut down before the task could be executed');
                        } finally {
                            $pending--;
                        }

                        if (!$worker->isRunning()) {
                            throw new WorkerException('Worker factory did not create a viable worker');
                        }

                        $workers->offsetSet($worker, 0);
                        return $worker;
                    });

                    $waiting = $this->waiting;
                    $deferredCancellation = $this->deferredCancellation;
                    $future
                        ->map($this->push)
                        ->catch(static function (\Throwable $e) use ($deferredCancellation, $workers, $waiting): void {
                            $deferredCancellation->cancel();
                            self::killWorkers($workers, $waiting, $e);
                        })
                        ->ignore();
                }

                $worker = $deferredFuture->getFuture()->await();
            } else {
                // Shift a worker off the idle queue.
                $worker = $this->idleWorkers->shift();
            }

            if ($worker === null) {
                // Worker crashed when executing a Task, which should have failed.
                continue;
            }

            \assert($worker instanceof Worker);

            if ($worker->isRunning()) {
                return $worker;
            }

            // Worker crashed while idle; trigger error and remove it from the pool.
            EventLoop::queue(static function () use ($worker): void {
                try {
                    $worker->shutdown();
                    \trigger_error('Worker in pool exited unexpectedly', \E_USER_WARNING);
                } catch (\Throwable $exception) {
                    \trigger_error(
                        'Worker in pool crashed with exception on shutdown: ' . $exception->getMessage(),
                        \E_USER_WARNING,
                    );
                }
            });

            $this->workers->detach($worker);
        } while (true);
    }
}
