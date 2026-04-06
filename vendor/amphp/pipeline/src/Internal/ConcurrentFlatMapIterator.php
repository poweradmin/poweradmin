<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;
use function Amp\Future\await;

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
        $order = $ordered ? new Sequence : null;

        $stop = FlatMapOperation::getStopMarker();

        $futures = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $futures[] = async(static function () use ($queue, $iterator, $flatMap, $order, $stop): void {
                foreach ($iterator as $position => $value) {
                    try {
                        // The operation runs concurrently, but the emits are at the correct position
                        $iterable = $flatMap($value, $position);
                    } catch (\Throwable $exception) {
                        $order?->await($position);
                        throw $exception;
                    }

                    $order?->await($position);

                    foreach ($iterable as $item) {
                        if ($item === $stop) {
                            $queue->complete();
                            break 2;
                        }

                        $queue->push($item);
                    }

                    $order?->resume($position);
                }
            });
        }

        async(static function () use ($futures, $queue): void {
            try {
                await($futures);
                $queue->complete();
            } catch (\Throwable $e) {
                $queue->error($e);
            }
        });
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        return $this->iterator->continue($cancellation);
    }

    public function getValue(): mixed
    {
        return $this->iterator->getValue();
    }

    public function getPosition(): int
    {
        return $this->iterator->getPosition();
    }

    public function dispose(): void
    {
        $this->iterator->dispose();
    }

    public function isComplete(): bool
    {
        return $this->iterator->isComplete();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
