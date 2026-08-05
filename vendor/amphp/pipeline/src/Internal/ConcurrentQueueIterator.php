<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;

/**
 * @internal
 *
 * @template-covariant T
 * @implements ConcurrentIterator<T>
 */
final class ConcurrentQueueIterator implements ConcurrentIterator
{
    private readonly QueueState $state;

    public function __construct(QueueState $state)
    {
        $this->state = $state;
    }

    public function __destruct()
    {
        $this->state->dispose();
    }

    #[\Override]
    public function continue(?Cancellation $cancellation = null): bool
    {
        return $this->state->continue($cancellation);
    }

    #[\Override]
    public function getValue(): mixed
    {
        return $this->state->getValue();
    }

    #[\Override]
    public function getPosition(): int
    {
        return $this->state->getPosition();
    }

    #[\Override]
    public function dispose(): void
    {
        $this->state->dispose();
    }

    #[\Override]
    public function isComplete(): bool
    {
        return $this->state->isConsumed() || $this->state->isDisposed();
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        while ($this->state->continue()) {
            yield $this->state->getPosition() => $this->state->getValue();
        }
    }
}
