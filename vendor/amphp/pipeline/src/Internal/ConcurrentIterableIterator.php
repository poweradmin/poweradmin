<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;

/**
 * @internal
 *
 * @template-covariant T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentIterableIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T> */
    private readonly ConcurrentIterator $iterator;

    /**
     * @param iterable<T> $iterable
     */
    public function __construct(iterable $iterable, int $bufferSize = 0)
    {
        if (\is_array($iterable)) {
            $this->iterator = new ConcurrentArrayIterator($iterable);
            return;
        }

        while ($iterable instanceof \IteratorAggregate) {
            if ($iterable instanceof ConcurrentIterator) {
                $this->iterator = $iterable;
                return;
            }

            $iterable = $iterable->getIterator();
        }

        $queue = new QueueState($bufferSize);
        $this->iterator = new ConcurrentQueueIterator($queue);

        async(static function () use ($queue, $iterable): void {
            try {
                foreach ($iterable as $value) {
                    $queue->push($value);
                }

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

    public function isComplete(): bool
    {
        return $this->iterator->isComplete();
    }

    public function dispose(): void
    {
        $this->iterator->dispose();
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator;
    }
}
