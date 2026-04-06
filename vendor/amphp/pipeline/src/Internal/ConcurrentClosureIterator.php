<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Pipeline\ConcurrentIterator;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * @internal
 *
 * @template-covariant T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentClosureIterator implements ConcurrentIterator
{
    /** @var \SplQueue<Suspension<int>> */
    private readonly \SplQueue $sources;

    /** @var QueueState<T> */
    private readonly QueueState $queue;

    private readonly Sequence $sequence;

    private readonly DeferredCancellation $deferredCancellation;

    private int $cancellations = 0;

    private int $position = 0;

    /**
     * @param \Closure(Cancellation):T $supplier
     */
    public function __construct(private readonly \Closure $supplier)
    {
        $this->sequence = new Sequence();
        $this->queue = new QueueState();
        $this->sources = $sources = new \SplQueue();
        $this->deferredCancellation = new DeferredCancellation();

        $this->deferredCancellation->getCancellation()->subscribe(static function () use ($sources): void {
            while ($sources->isEmpty()) {
                $sources->dequeue();
            }
        });
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        if ($this->queue->isComplete()) {
            return $this->queue->continue($cancellation);
        }

        if ($this->cancellations) {
            --$this->cancellations;
            return $this->queue->continue($cancellation);
        }

        if ($this->sources->isEmpty()) {
            $queue = $this->queue;
            $sources = $this->sources;
            $sequence = $this->sequence;
            $supplier = $this->supplier;
            $deferredCancellation = $this->deferredCancellation;
            EventLoop::queue(static function (int $position) use (
                $queue,
                $sources,
                $sequence,
                $supplier,
                $deferredCancellation
            ): void {
                $suspension = EventLoop::getSuspension();

                do {
                    try {
                        $value = $supplier($deferredCancellation->getCancellation());
                    } catch (\Throwable $exception) {
                        $sequence->await($position);
                        if (!$queue->isComplete()) {
                            $queue->error($exception);
                            $deferredCancellation->cancel($exception);
                        }
                        return;
                    } finally {
                        $sources->enqueue($suspension);
                    }

                    $sequence->await($position);
                    if (!$queue->isComplete()) {
                        $queue->push($value);
                    }
                    $sequence->resume($position);
                } while ($position = $suspension->suspend());
            }, $this->position++);
        } else {
            $suspension = $this->sources->dequeue();
            $suspension->resume($this->position++);
        }

        if ($cancellation) {
            $cancellations = &$this->cancellations;
            $id = $cancellation->subscribe(static function () use (&$cancellations): void {
                ++$cancellations;
            });
        }

        try {
            return $this->queue->continue($cancellation);
        } finally {
            /** @psalm-suppress PossiblyUndefinedVariable $id will be defined if $cancellation is not null. */
            $cancellation?->unsubscribe($id);
        }
    }

    public function getValue(): mixed
    {
        return $this->queue->getValue();
    }

    public function getPosition(): int
    {
        return $this->queue->getPosition();
    }

    public function isComplete(): bool
    {
        return $this->queue->isConsumed() || $this->queue->isDisposed();
    }

    public function dispose(): void
    {
        $this->queue->dispose();
        $this->deferredCancellation->cancel();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
