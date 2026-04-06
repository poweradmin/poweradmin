<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\Cancellation;

/**
 * @template-covariant T
 * @template-extends \IteratorAggregate<int, T>
 */
interface ConcurrentIterator extends \IteratorAggregate
{
    /**
     * Advances the iterator to the next position and value for the current fiber.
     *
     * The position and value must be available via {@see getPosition()} and {@see getValue()} to the fiber calling
     * {@see continue()} only.
     *
     * A fiber calling {@see continue()} must not affect the position or value of other fibers.
     *
     * If the iterator errors, the exception will be thrown from this method.
     *
     * @param Cancellation|null $cancellation Cancels waiting for the next value. If cancelled, the next value is not
     *     lost, but will be available to the next call to this method.
     *
     * @return bool `true` if a value is available, `false` if the iterator has completed.
     */
    public function continue(?Cancellation $cancellation = null): bool;

    /**
     * Returns the current value of the iterator for the current fiber.
     *
     * Advance the iterator to the next value using {@see continue()}, which must be called before this method may be
     * called for each value.
     *
     * @return T The current value of the iterator. If the iterator has completed or {@see continue()} has
     * not been called, an {@see \Error} will be thrown.
     */
    public function getValue(): mixed;

    /**
     * Returns the current position of the iterator for the current fiber.
     *
     * Advance the iterator to the next position using {@see continue()}, which must be called before this method may be
     * called for each position.
     *
     * @return int The current position of the iterator. If the iterator has completed or {@see continue()} has
     * not been called, an {@see \Error} will be thrown.
     */
    public function getPosition(): int;

    /**
     * @return bool `true` if the iterator has completed (either successfully or with an error) or `false`
     * if the iterator may still emit more values.
     */
    public function isComplete(): bool;

    /**
     * Disposes the iterator, indicating the consumer is no longer interested in the iterator output.
     */
    public function dispose(): void;

    /**
     * @return \Traversable<int, T> Returns an iterator with {@see getPosition()} as key and {@see getValue()} as
     *     value. Multiple calls must be allowed to allow for concurrent iteration.
     */
    public function getIterator(): \Traversable;
}
