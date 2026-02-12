<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @internal
 *
 * @template-covariant T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentMergedIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T> */
    private readonly ConcurrentIterator $iterator;

    private readonly DeferredCancellation $deferredCancellation;

    /**
     * @param ConcurrentIterator<T>[] $iterators
     */
    public function __construct(array $iterators)
    {
        foreach ($iterators as $key => $iterator) {
            if (!$iterator instanceof ConcurrentIterator) {
                throw new \TypeError(\sprintf(
                    'Argument #1 ($iterators) must be of type array<%s>, %s given at key %s',
                    ConcurrentIterator::class,
                    \get_debug_type($iterator),
                    $key
                ));
            }
        }

        $queue = new Queue(\count($iterators));
        $this->iterator = $queue->iterate();

        $this->deferredCancellation = $deferredCancellation = new DeferredCancellation();
        $cancellation = $this->deferredCancellation->getCancellation();

        $futures = [];
        foreach ($iterators as $iterator) {
            $futures[] = async(static function () use ($iterator, $queue, $cancellation): void {
                try {
                    while ($iterator->continue($cancellation)) {
                        if ($queue->isComplete()) {
                            return;
                        }

                        $queue->push($iterator->getValue());
                    }
                } finally {
                    $iterator->dispose();
                }
            });
        }

        EventLoop::queue(static function () use ($futures, $queue, $deferredCancellation): void {
            try {
                Future\await($futures);
                $queue->complete();
            } catch (\Throwable $exception) {
                $queue->error($exception);
            } finally {
                $deferredCancellation->cancel();
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
        $this->deferredCancellation->cancel();
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator;
    }
}
