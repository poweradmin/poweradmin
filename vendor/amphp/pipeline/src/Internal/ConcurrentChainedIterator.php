<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Revolt\EventLoop\FiberLocal;

/**
 * Concatenates the given iterators into a single iterator in sequential order.
 *
 * The prior iterator must complete before values are taken from any subsequent iterators.
 *
 * @internal
 *
 * @template-covariant T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentChainedIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T>[] */
    private readonly array $iterators;

    /** @var FiberLocal<int|null> */
    private readonly FiberLocal $position;

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

        $this->iterators = \array_values($iterators);
        $this->position = new FiberLocal(static fn () => 0);
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        $position = $this->position->get();

        while (isset($this->iterators[$position])) {
            if ($this->iterators[$position]->continue($cancellation)) {
                return true;
            }

            $this->position->set(++$position);
        }

        $this->position->set(null);

        return false;
    }

    public function getValue(): mixed
    {
        $position = $this->position->get();
        if ($position === null) {
            throw new \Error('No value available anymore, check continue() return value');
        }

        return $this->iterators[$position]->getValue();
    }

    public function getPosition(): int
    {
        $position = $this->position->get();
        if ($position === null) {
            throw new \Error('No value available anymore, check continue() return value');
        }

        return $this->iterators[$position]->getPosition();
    }

    public function isComplete(): bool
    {
        return $this->position->get() !== null;
    }

    public function dispose(): void
    {
        foreach ($this->iterators as $iterator) {
            $iterator->dispose();
        }
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
