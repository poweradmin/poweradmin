<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterator;

/**
 * @template T
 * @template R
 *
 * @internal
 */
final class FlatMapOperation implements IntermediateOperation
{
    public static function getStopMarker(): object
    {
        static $marker;

        return $marker ??= new \stdClass;
    }

    /**
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(
        private readonly int $bufferSize,
        private readonly int $concurrency,
        private readonly bool $ordered,
        private readonly \Closure $flatMap
    ) {
    }

    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        if ($this->concurrency === 1) {
            $stop = self::getStopMarker();

            return new ConcurrentIterableIterator((function () use ($source, $stop): iterable {
                foreach ($source as $position => $value) {
                    $iterable = ($this->flatMap)($value, $position);
                    foreach ($iterable as $item) {
                        if ($item === $stop) {
                            return;
                        }

                        yield $item;
                    }
                }
            })(), $this->bufferSize);
        }

        return new ConcurrentFlatMapIterator(
            $source,
            $this->bufferSize,
            $this->concurrency,
            $this->ordered,
            $this->flatMap,
        );
    }
}
