<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Internal;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Revolt\EventLoop;
use Revolt\EventLoop\FiberLocal;
use Revolt\EventLoop\Suspension;

/**
 * Class used internally by {@see Pipeline} implementations. Do not use this class in your code, instead compose your
 * class from one of the available classes implementing {@see ConcurrentIterator}.
 *
 * @internal
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class QueueState implements \IteratorAggregate
{
    private const CONTINUE = [null];

    private bool $completed = false;

    private ?\Throwable $exception = null;

    /** @var array<int, T> */
    private array $emittedValues = [];

    /** @var array<int, DeferredFuture<null>|Suspension> */
    private array $backpressure = [];

    /** @var Suspension[] */
    private array $waiting = [];

    private int $consumePosition = 0;

    private int $emitPosition = 0;

    private ?array $resolutionTrace = null;

    private bool $disposed = false;

    private int $bufferSize;

    private int $positionOffset = 0;

    /** @var FiberLocal<int|null> */
    private readonly FiberLocal $currentPosition;

    /** @var FiberLocal<T|null> */
    private readonly FiberLocal $currentValue;

    public function __construct(int $bufferSize = 0)
    {
        if ($bufferSize < 0) {
            throw new \ValueError('Argument #1 ($bufferSize) must be greater than or equal to 0, got ' . $bufferSize);
        }

        $this->bufferSize = $bufferSize;
        $this->currentPosition = new FiberLocal(static fn () => throw new \Error('Call continue() before calling getPosition()'));
        $this->currentValue = new FiberLocal(static fn () => throw new \Error('Call continue() before calling getValue()'));
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        $position = $this->consumePosition++;
        $backpressurePosition = $position + $this->bufferSize;

        // Relieve backpressure from prior emit.
        if (isset($this->backpressure[$backpressurePosition])) {
            $backpressure = $this->backpressure[$backpressurePosition];
            unset($this->backpressure[$backpressurePosition]);
            $backpressure instanceof Suspension
                ? $backpressure->resume()
                : $backpressure->complete();
        }

        if (\array_key_exists($position, $this->emittedValues)) {
            $value = $this->emittedValues[$position];
            unset($this->emittedValues[$position]);
            $this->currentPosition->set($position - $this->positionOffset);
            $this->currentValue->set($value);
            return true;
        }

        if ($this->completed || $this->disposed) {
            $this->currentPosition->set(null);
            $this->currentValue->set(null);

            if ($this->exception) {
                throw $this->exception;
            }

            return false;
        }

        $this->currentValue->set(null); // Remove reference to current value while awaiting next value.

        // No value has been emitted, suspend fiber to await next value.
        $this->waiting[] = $suspension = EventLoop::getSuspension();

        if ($cancellation) {
            $waiting = &$this->waiting;
            $offset = &$this->positionOffset;
            $id = $cancellation->subscribe(static function (\Throwable $exception) use (
                &$waiting,
                &$offset,
                $suspension,
            ): void {
                foreach ($waiting as $key => $pending) {
                    if ($pending === $suspension) {
                        unset($waiting[$key]);
                        ++$offset;
                        $suspension->throw($exception);
                        return;
                    }
                }
            });
        }

        try {
            $value = $suspension->suspend();

            // This is just a marker, because we can't set fiber locals from other fibers
            if ($value === $this->currentPosition) {
                $this->currentPosition->set(null);
                $this->currentValue->set(null);

                return false;
            }

            $this->currentPosition->set($position - $this->positionOffset);
            $this->currentValue->set($value);

            return true;
        } finally {
            /** @psalm-suppress PossiblyUndefinedVariable $id will be defined if $cancellation is not null. */
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * @return T
     */
    public function getValue(): mixed
    {
        if ($this->currentPosition->get() === null) {
            throw new \Error('Pipeline complete or awaiting next value, cannot call getValue()');
        }

        return $this->currentValue->get();
    }

    public function getPosition(): int
    {
        $position = $this->currentPosition->get();
        if ($position === null) {
            throw new \Error('Pipeline complete, cannot call getPosition()');
        }

        return $position;
    }

    public function hasPending(): bool
    {
        return \array_key_exists($this->consumePosition, $this->emittedValues);
    }

    /**
     * @see Pipeline::dispose()
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        if ($this->completed) {
            $this->disposed = true;
            $this->exception = new DisposedException;
            $this->triggerDisposal();
            return;
        }

        $this->finalize(new DisposedException, true);
    }

    /**
     * @param T $value
     *
     * @return array|null Returns [?\Throwable, mixed] or null if no send value is available.
     *
     * @throws \Error If the queue has completed.
     */
    private function doPush(mixed $value, int $position): ?array
    {
        if ($this->completed) {
            throw new \Error("Values cannot be enqueued after calling complete");
        }

        if (!empty($this->waiting)) {
            $key = \array_key_first($this->waiting);
            $suspension = $this->waiting[$key];
            unset($this->waiting[$key]);
            $suspension->resume($value);

            if ($this->disposed && empty($this->waiting)) {
                $this->triggerDisposal();
                return self::CONTINUE; // Subsequent push() calls will throw.
            }

            if ($this->consumePosition > $position) {
                return self::CONTINUE;
            }

            return null;
        }

        if ($this->disposed) {
            \assert(isset($this->exception), "Failure exception must be set when disposed");
            // Pipeline has been disposed and no Fibers are still pending.
            return [$this->exception];
        }

        $this->emittedValues[$position] = $value;

        if (\count($this->emittedValues) > $this->bufferSize) {
            return null;
        }

        return self::CONTINUE;
    }

    /**
     * Emits a value from the pipeline. The returned promise is resolved once the emitted value has been consumed or
     * if the pipeline is completed, failed, or disposed.
     *
     * @param T $value Value to emit from the pipeline.
     *
     * @return Future Resolves once the value has been consumed on the pipeline.
     */
    public function pushAsync(mixed $value): Future
    {
        $position = $this->emitPosition++ + $this->positionOffset;
        $next = $this->doPush($value, $position);

        if ($next === null) {
            $this->backpressure[$position] = $deferred = new DeferredFuture;
            return $deferred->getFuture();
        }

        [$exception] = $next;

        if ($exception) {
            return Future::error($exception);
        }

        return Future::complete();
    }

    /**
     * Emits a value from the pipeline, suspending execution until the value is consumed.
     *
     * @param T $value Value to emit from the pipeline.
     */
    public function push(mixed $value): void
    {
        $position = $this->emitPosition++ + $this->positionOffset;
        $next = $this->doPush($value, $position);

        if ($next === null) {
            $this->backpressure[$position] = $suspension = EventLoop::getSuspension();
            $suspension->suspend();
            return;
        }

        [$exception] = $next;

        if ($exception) {
            throw $exception;
        }
    }

    /**
     * @return bool True if the pipeline has been completed or failed.
     */
    public function isComplete(): bool
    {
        return $this->completed;
    }

    /**
     * @return bool True if the pipeline has no values pending and has been completed.
     */
    public function isConsumed(): bool
    {
        return empty($this->emittedValues) && $this->completed;
    }

    /**
     * @return bool True if the pipeline was disposed.
     */
    public function isDisposed(): bool
    {
        return $this->disposed && empty($this->waiting);
    }

    /**
     * Completes the pipeline.
     *
     * @throws \Error If the iterator has already been completed.
     */
    public function complete(): void
    {
        $this->finalize();
    }

    /**
     * Fails the pipeline.
     */
    public function error(\Throwable $exception): void
    {
        $this->finalize($exception);
    }

    /**
     * @param \Throwable|null $exception Failure reason or null for success.
     * @param bool $disposed Flag if the generator was disposed.
     */
    private function finalize(?\Throwable $exception = null, bool $disposed = false): void
    {
        if ($this->completed) {
            $message = "Queue has already been completed";

            if (isset($this->resolutionTrace)) {
                /** @psalm-suppress ArgumentTypeCoercion $this->resolution trace generated from debug_backtrace() */
                $trace = Internal\formatStacktrace($this->resolutionTrace);
                $message .= ". Previous completion trace:\n\n{$trace}\n\n";
            } else {
                // @codeCoverageIgnoreStart
                $message .= ", define environment variable AMP_DEBUG or const AMP_DEBUG = true and enable assertions "
                    . "for a stacktrace of the previous resolution.";
                // @codeCoverageIgnoreEnd
            }

            throw new \Error($message);
        }

        $this->completed = !$disposed; // $disposed is false if complete() or error() invoked
        $this->disposed = $this->disposed ?: $disposed; // Once disposed, do not change flag

        if ($this->completed) { // Record stack trace when calling complete() or error()
            \assert((function () {
                if (Internal\isDebugEnabled()) {
                    $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
                    \array_shift($trace); // remove current closure
                    $this->resolutionTrace = $trace;
                }

                return true;
            })());
        }

        if (isset($this->exception)) {
            return;
        }

        if ($exception !== null) {
            $this->exception = $exception;
        }

        if ($this->disposed) {
            $this->triggerDisposal();
        } else {
            $this->resolvePending();
        }
    }

    private function relieveBackPressure(?\Throwable $exception): void
    {
        $backPressure = $this->backpressure;
        $this->backpressure = [];

        foreach ($backPressure as $placeholder) {
            if ($exception) {
                $placeholder instanceof Suspension
                    ? $placeholder->throw($exception)
                    : $placeholder->error($exception);
            } else {
                $placeholder instanceof Suspension
                    ? $placeholder->resume()
                    : $placeholder->complete();
            }
        }
    }

    /**
     * Resolves all backpressure and outstanding calls for emitted values.
     */
    private function resolvePending(): void
    {
        $waiting = $this->waiting;
        $this->waiting = [];

        foreach ($waiting as $suspension) {
            if ($this->exception) {
                $suspension->throw($this->exception);
            } else {
                $suspension->resume($this->currentPosition);
            }
        }
    }

    /**
     * Fails pending {@see continue()} promises.
     */
    private function triggerDisposal(): void
    {
        \assert($this->disposed && $this->exception, "Pipeline was not disposed on triggering disposal");

        /** @psalm-suppress RedundantCondition */
        if (isset($this->backpressure)) {
            $this->relieveBackPressure($this->exception);
        }

        /** @psalm-suppress RedundantCondition */
        if (isset($this->waiting)) {
            $this->resolvePending();
        }
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
