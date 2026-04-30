<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\Future;
use Amp\Pipeline\Internal\ConcurrentQueueIterator;

/**
 * Queue is an ordered sequence of values with support for concurrent consumption.
 *
 * {@see complete()} can be used to signal completeness of the queue, no new items will be queued.
 *
 * {@see error()} can be used to signal errors in the queue, no new items can be queued.
 *
 * @template T
 */
final class Queue
{
    /** @var Internal\QueueState<T> Has public emit, complete, and fail methods. */
    private readonly Internal\QueueState $state;

    /**
     * @param int $bufferSize Allowed number of items to internally buffer before awaiting backpressure from the
     * consumer of the queue.
     */
    public function __construct(int $bufferSize = 0)
    {
        $this->state = new Internal\QueueState($bufferSize);
    }

    /**
     * Returns a {@see Pipeline} to consume the queue.
     *
     * @return Pipeline<T>
     */
    public function pipe(): Pipeline
    {
        return new Pipeline($this->iterate());
    }

    /**
     * Returns a {@see ConcurrentIterator} to consume the queue.
     *
     * @return ConcurrentIterator<T>
     */
    public function iterate(): ConcurrentIterator
    {
        return new ConcurrentQueueIterator($this->state);
    }

    /**
     * Enqueues a value to the queue, returning a future that is completed once the value is inserted into the buffer
     * or consumed in case of an unbuffered queue.
     *
     * {@see await()} the {@see Future} returned at a later time, or use {@see push()} to await the value being
     * inserted into the buffer or consumed immediately.
     *
     * @param T $value
     *
     * @return Future<null> Completes with null when the emitted value has been consumed or errors with
     *                       {@see DisposedException} if the queue has been disposed.
     */
    public function pushAsync(mixed $value): Future
    {
        return $this->state->pushAsync($value);
    }

    /**
     * Pushes a value to the buffer or waits until the value is consumed if the buffer is full or the queue is
     * unbuffered.
     *
     * Use {@see pushAsync()} to push a value without waiting for consumption or free buffer space.
     *
     * @param T $value
     *
     * @throws DisposedException Thrown if the queue is disposed.
     */
    public function push(mixed $value): void
    {
        $this->state->push($value);
    }

    /**
     * @return bool True if the queue has been completed or errored.
     */
    public function isComplete(): bool
    {
        return $this->state->isComplete();
    }

    /**
     * @return bool True if the queue has been disposed.
     */
    public function isDisposed(): bool
    {
        return $this->state->isDisposed();
    }

    /**
     * Completes the queue.
     */
    public function complete(): void
    {
        $this->state->complete();
    }

    /**
     * Errors the queue with the given reason.
     */
    public function error(\Throwable $reason): void
    {
        $this->state->error($reason);
    }
}
