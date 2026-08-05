<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;

/**
 * @internal
 *
 * @template-covariant T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentFlatMapIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T> */
    private readonly ConcurrentIterator $iterator;

    /**
     * @template R
     *
     * @param ConcurrentIterator<T> $iterator
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(
        ConcurrentIterator $iterator,
        int $bufferSize,
        int $concurrency,
        bool $ordered,
        \Closure $flatMap,
    ) {
        $queue = new QueueState($bufferSize);
        $this->iterator = new ConcurrentQueueIterator($queue);

        $preOrder = $ordered ? new Sequence() : null;
        $postOrder = $ordered ? new Sequence() : null;

        $stop = FlatMapOperation::getStopMarker();

        $futures = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $futures[] = async(static function () use (
                $queue,
                $iterator,
                $flatMap,
                $preOrder,
                $postOrder,
                $stop,
            ): void {
                foreach ($iterator as $position => $value) {
                    // Force ordering of concurrent coroutines regardless of the emitted order of the source iterator.
                    $preOrder?->barrier($position);

                    try {
                        $iterable = $flatMap($value, $position);
                    } catch (\Throwable $exception) {
                        $postOrder?->await($position);

                        $preOrder?->dispose();
                        $postOrder?->dispose();

                        throw $exception;
                    }

                    $postOrder?->await($position);

                    foreach ($iterable as $item) {
                        // Another concurrent coroutine already completed the queue
                        if ($queue->isComplete()) {
                            return;
                        }

                        if ($item === $stop) {
                            $queue->complete();

                            $preOrder?->dispose();
                            $postOrder?->dispose();

                            return;
                        }

                        $queue->push($item);
                    }

                    $postOrder?->resume($position);
                }
            });
        }

        async(static function () use ($futures, $queue): void {
            try {
                Future\await($futures);

                if (!$queue->isComplete()) {
                    $queue->complete();
                }
            } catch (\Throwable $e) {
                if (!$queue->isComplete()) {
                    $queue->error($e);
                }
            }
        });
    }

    #[\Override]
    public function continue(?Cancellation $cancellation = null): bool
    {
        return $this->iterator->continue($cancellation);
    }

    #[\Override]
    public function getValue(): mixed
    {
        return $this->iterator->getValue();
    }

    #[\Override]
    public function getPosition(): int
    {
        return $this->iterator->getPosition();
    }

    #[\Override]
    public function dispose(): void
    {
        $this->iterator->dispose();
    }

    #[\Override]
    public function isComplete(): bool
    {
        return $this->iterator->isComplete();
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
