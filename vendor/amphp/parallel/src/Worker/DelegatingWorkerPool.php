<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Worker\Internal\PooledWorker;

final class DelegatingWorkerPool implements LimitedWorkerPool
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var array<int, Worker> */
    private array $workerStorage = [];

    private int $pendingWorkerCount = 0;

    /** @var \SplQueue<DeferredFuture<Worker|null>> */
    private readonly \SplQueue $waiting;

    /**
     * @param int $limit Maximum number of workers to use from the delegate pool.
     */
    public function __construct(private readonly WorkerPool $pool, private readonly int $limit)
    {
        $this->waiting = new \SplQueue();
    }

    public function isRunning(): bool
    {
        return $this->pool->isRunning();
    }

    public function isIdle(): bool
    {
        return $this->pool->isIdle();
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        $worker = $this->selectWorker();

        $execution = $worker->submit($task, $cancellation);

        $execution->getFuture()->finally(fn () => $this->push($worker))->ignore();

        return $execution;
    }

    private function selectWorker(): Worker
    {
        do {
            if (\count($this->workerStorage) + $this->pendingWorkerCount < $this->limit) {
                $this->pendingWorkerCount++;

                try {
                    $worker = $this->pool->getWorker();
                } finally {
                    $this->pendingWorkerCount--;
                }
            } else {
                /** @var DeferredFuture<Worker|null> $waiting */
                $waiting = new DeferredFuture();
                $this->waiting->push($waiting);

                $worker = $waiting->getFuture()->await();
                if (!$worker?->isRunning()) {
                    continue;
                }
            }

            $this->workerStorage[\spl_object_id($worker)] = $worker;

            return $worker;
        } while (true);
    }

    private function push(Worker $worker): void
    {
        unset($this->workerStorage[\spl_object_id($worker)]);

        if (!$this->waiting->isEmpty()) {
            $deferredFuture = $this->waiting->dequeue();
            $deferredFuture->complete($worker->isRunning() ? $worker : null);
        }
    }

    public function shutdown(): void
    {
        if (!$this->waiting->isEmpty()) {
            $exception = new WorkerException('The pool was shutdown before a worker could be obtained');
            $this->clearWaiting($exception);
        }

        $this->pool->shutdown();
    }

    public function kill(): void
    {
        if (!$this->waiting->isEmpty()) {
            $exception = new WorkerException('The pool was killed before a worker could be obtained');
            $this->clearWaiting($exception);
        }

        $this->pool->kill();
    }

    private function clearWaiting(\Throwable $exception): void
    {
        while (!$this->waiting->isEmpty()) {
            $deferredFuture = $this->waiting->dequeue();
            $deferredFuture->error($exception);
        }
    }

    public function getWorker(): Worker
    {
        $worker = $this->selectWorker();
        return new PooledWorker($worker, $this->push(...));
    }

    public function getWorkerLimit(): int
    {
        return $this->limit;
    }

    public function getWorkerCount(): int
    {
        return \min($this->limit, $this->pool->getWorkerCount());
    }

    public function getIdleWorkerCount(): int
    {
        return \min($this->limit, $this->pool->getIdleWorkerCount());
    }
}
