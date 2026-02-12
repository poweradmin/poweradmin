<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\Cancellation;
use Amp\Pipeline\Internal\ConcurrentArrayIterator;
use Amp\Pipeline\Internal\ConcurrentChainedIterator;
use Amp\Pipeline\Internal\ConcurrentClosureIterator;
use Amp\Pipeline\Internal\ConcurrentIterableIterator;
use Amp\Pipeline\Internal\ConcurrentMergedIterator;
use Amp\Pipeline\Internal\FlatMapOperation;
use Amp\Pipeline\Internal\IntermediateOperation;
use Amp\Pipeline\Internal\Sequence;
use Amp\Pipeline\Internal\SortOperation;
use function Amp\delay;

/**
 * A pipeline represents an asynchronous set and provides operations which can be applied over the set.
 *
 * @template T
 * @template-implements \IteratorAggregate<int, T>
 */
final class Pipeline implements \IteratorAggregate
{
    /**
     * Creates a pipeline from the given iterable or closure returning an iterable.
     *
     * @template Ts
     *
     * @param (\Closure():iterable<Ts>)|iterable<Ts> $iterable
     *
     * @return self<Ts>
     */
    public static function fromIterable(\Closure|iterable $iterable): self
    {
        if ($iterable instanceof \Closure) {
            $iterable = $iterable();

            if (!\is_iterable($iterable)) {
                throw new \TypeError('Return value of argument #1 ($iterable) must be of type iterable, ' . \get_debug_type($iterable) . ' returned');
            }
        }

        if ($iterable instanceof self) {
            return $iterable;
        }

        if ($iterable instanceof ConcurrentIterator) {
            return new self($iterable);
        }

        if (\is_array($iterable)) {
            return new self(new ConcurrentArrayIterator($iterable));
        }

        return new self(new ConcurrentIterableIterator($iterable));
    }

    /**
     * Creates an infinite pipeline from the given closure invoking it repeatedly for each value.
     *
     * @template Ts
     *
     * @param \Closure(Cancellation): Ts $supplier Elements to emit.
     *
     * @return self<Ts>
     */
    public static function generate(\Closure $supplier): Pipeline
    {
        return new self(new ConcurrentClosureIterator($supplier));
    }

    /**
     * Merges the given iterables into a single pipeline. The returned pipeline emits a value anytime one of the
     * merged iterables produces a value.
     *
     * @template Ts
     *
     * @param array<iterable<Ts>> $pipelines
     *f
     * @return self<Ts>
     */
    public static function merge(array $pipelines): self
    {
        return new self(new ConcurrentMergedIterator(self::mapToConcurrentIterators($pipelines)));
    }

    /**
     * Concatenates the given iterables into a single pipeline in sequential order.
     *
     * The prior pipeline must complete before values are taken from any subsequent pipelines.
     *
     * @template Ts
     *
     * @param array<iterable<Ts>> $pipelines
     *
     * @return self<Ts>
     */
    public static function concat(array $pipelines): self
    {
        return new self(new ConcurrentChainedIterator(self::mapToConcurrentIterators($pipelines)));
    }

    /**
     * @template Tk of array-key
     * @template Ts
     *
     * @param array<Tk, iterable<Ts>> $iterables
     *
     * @return array<Tk, ConcurrentIterator<Ts>>
     */
    private static function mapToConcurrentIterators(array $iterables): array
    {
        foreach ($iterables as $key => $iterable) {
            if (!\is_iterable($iterable)) {
                throw new \TypeError(\sprintf(
                    'Argument #1 ($pipelines) must be of type array<iterable>, %s given at key %s',
                    \get_debug_type($iterable),
                    $key,
                ));
            }
        }

        return \array_map(static fn (iterable $pipeline) => self::fromIterable($pipeline)->getIterator(), $iterables);
    }

    /** @var non-negative-int */
    private int $bufferSize = 0;

    /** @var positive-int */
    private int $concurrency = 1;

    private bool $ordered = true;

    /** @var list<IntermediateOperation> */
    private array $intermediateOperations = [];

    private bool $used = false;

    /**
     * @param ConcurrentIterator<T> $source
     */
    public function __construct(
        private readonly ConcurrentIterator $source,
    ) {
    }

    public function __destruct()
    {
        if (!$this->used) {
            $this->source->dispose();
        }
    }

    public function buffer(int $bufferSize): self
    {
        if ($bufferSize < 0) {
            throw new \ValueError('Argument #1 ($bufferSize) must be non-negative, got ' . $bufferSize);
        }

        $this->bufferSize = $bufferSize;

        return $this;
    }

    public function concurrent(int $concurrency): self
    {
        if ($concurrency < 1) {
            throw new \ValueError('Argument #1 ($concurrency) must be positive, got ' . $concurrency);
        }

        $this->concurrency = $concurrency;

        return $this;
    }

    public function sequential(): self
    {
        return $this->concurrent(1);
    }

    public function ordered(): self
    {
        $this->ordered = true;

        return $this;
    }

    public function unordered(): self
    {
        $this->ordered = false;

        return $this;
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * @template R
     *
     * @param null|\Closure(T, T): int $compare
     * @param R $default
     *
     * @return T|R
     */
    public function min(?\Closure $compare = null, mixed $default = null): mixed
    {
        $compare ??= static fn (mixed $a, mixed $b): int => $a <=> $b;
        $min = $default;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $min = $value;
            } else {
                /** @var T $min */
                $comparison = $compare($min, $value);
                if ($comparison > 0) {
                    $min = $value;
                }
            }
        }

        return $min;
    }

    /**
     * @template R
     *
     * @param null|\Closure(T, T): int $compare
     * @param R $default
     *
     * @return T|R
     */
    public function max(?\Closure $compare = null, mixed $default = null): mixed
    {
        $compare ??= static fn (mixed $a, mixed $b): int => $a <=> $b;
        $max = $default;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $max = $value;
            } else {
                /** @var T $max */
                $comparison = $compare($max, $value);
                if ($comparison < 0) {
                    $max = $value;
                }
            }
        }

        return $max;
    }

    /**
     * @param \Closure(T): bool $predicate
     */
    public function allMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if (!$value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \Closure(T): bool $predicate
     */
    public function anyMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if ($value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Closure(T): bool $predicate
     */
    public function noneMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if ($value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Invokes the given callback for each value emitted on the pipeline.
     *
     * @param \Closure(T):void $forEach
     */
    public function forEach(\Closure $forEach): void
    {
        $this->tap($forEach)->count();
    }

    /**
     * Collects all items into an array.
     *
     * @return list<T>
     */
    public function toArray(): array
    {
        return \iterator_to_array($this, false);
    }

    /**
     * Sorts values, requires buffering all values.
     *
     * @template R
     *
     * @param null|\Closure(T, T):int $compare
     *
     * @return self<R>
     */
    public function sorted(?\Closure $compare = null): self
    {
        if ($this->used) {
            throw new \Error('Pipeline consumption has already been started');
        }

        $compare ??= static fn (mixed $a, mixed $b): int => $a <=> $b;
        $this->intermediateOperations[] = new SortOperation($compare);

        return $this->ordered();
    }

    /**
     * Maps values, flattening one level.
     *
     * @template R
     *
     * @param \Closure(T, int):iterable<R> $flatMap
     *
     * @return self<R>
     */
    public function flatMap(\Closure $flatMap): self
    {
        if ($this->used) {
            throw new \Error('Pipeline consumption has already been started');
        }

        $this->intermediateOperations[] = new FlatMapOperation(
            $this->bufferSize,
            $this->concurrency,
            $this->ordered,
            $flatMap,
        );

        /** @var self<R> */
        return $this;
    }

    /**
     * Maps values.
     *
     * @template R
     *
     * @param \Closure(T):R $map
     *
     * @return self<R>
     */
    public function map(\Closure $map): self
    {
        return $this->flatMap(static fn (mixed $value) => [$map($value)]);
    }

    /**
     * Filters values.
     *
     * @param \Closure(T):bool $filter Keep value if {@code $filter} returns {@code true}.
     *
     * @return self<T>
     */
    public function filter(\Closure $filter): self
    {
        return $this->flatMap(static fn (mixed $value) => $filter($value) ? [$value] : []);
    }

    /**
     * Invokes the given function each time a value is streamed through the pipeline to perform side effects.
     *
     * @param \Closure(T):void $tap
     *
     * @return self<T>
     */
    public function tap(\Closure $tap): self
    {
        return $this->flatMap(static function (mixed $value) use ($tap) {
            $tap($value);

            return [$value];
        });
    }

    /**
     * @template R
     *
     * @param \Closure(R, T): R $accumulator
     * @param R $initial
     *
     * @return R
     */
    public function reduce(\Closure $accumulator, mixed $initial = null)
    {
        $result = $initial;

        foreach ($this as $value) {
            $result = $accumulator($result, $value);
        }

        return $result;
    }

    /**
     * Delays each item by $delay seconds.
     *
     *
     * @return self<T>
     */
    public function delay(float $delay): self
    {
        return $this->tap(static fn () => delay($delay));
    }

    /**
     * Skip the first N items of the pipeline.
     *
     * @return self<T>
     */
    public function skip(int $count): self
    {
        return $this->flatMap(static function (mixed $value) use ($count) {
            static $i = 0;

            if ($i++ < $count) {
                return [];
            }

            return [$value];
        });
    }

    /**
     * Skips values on the pipeline until {@code $predicate} returns {@code false}.
     *
     * All values are emitted afterwards without invoking {@code $predicate}.
     *
     * @param \Closure(T):bool $predicate
     *
     * @return self<T>
     */
    public function skipWhile(\Closure $predicate): self
    {
        $sequence = new Sequence;
        $skipping = true;

        return $this->flatMap(
            static function (mixed $value, int $position) use ($sequence, $predicate, &$skipping) {
                if (!$skipping) {
                    return [$value];
                }

                $predicateResult = $predicate($value);

                $sequence->await($position);

                /** @psalm-suppress RedundantCondition */
                if ($skipping && $predicateResult) {
                    $sequence->resume($position);
                    return [];
                }

                $skipping = false;
                $sequence->resume($position);

                return [$value];
            }
        );
    }

    /**
     * Take only the first N items of the pipeline.
     *
     * @return self<T>
     */
    public function take(int $count): self
    {
        return $this->flatMap(static function (mixed $value) use ($count) {
            static $i = 0;

            if (++$i < $count) {
                return [$value];
            }

            /** @var T $stopMarker Fake stop marker as type T. */
            $stopMarker = FlatMapOperation::getStopMarker();

            if ($i === $count) {
                return [$value, $stopMarker];
            }

            return [$stopMarker];
        });
    }

    /**
     * Takes values on the pipeline until {@code $predicate} returns {@code false}.
     *
     * @param \Closure(T):bool $predicate
     *
     * @return self<T>
     */
    public function takeWhile(\Closure $predicate): self
    {
        $sequence = new Sequence;
        $taking = true;

        return $this->flatMap(
            static function (mixed $value, int $position) use ($sequence, $predicate, &$taking) {
                if (!$taking) {
                    return [];
                }

                $predicateResult = $predicate($value);

                $sequence->await($position);

                /** @psalm-suppress RedundantCondition */
                if ($taking && $predicateResult) {
                    $sequence->resume($position);
                    return [$value];
                }

                $taking = false;
                $sequence->resume($position);

                /** @var T[] */
                return [FlatMapOperation::getStopMarker()];
            }
        );
    }

    /**
     * @return ConcurrentIterator<T>
     */
    public function getIterator(): ConcurrentIterator
    {
        if ($this->used) {
            throw new \Error('Pipelines can\'t be reused after a terminal operation');
        }

        $this->used = true;

        $source = $this->source;

        foreach ($this->intermediateOperations as $intermediateOperation) {
            $source = $intermediateOperation($source);
        }

        return $source;
    }

    public function dispose(): void
    {
        $this->source->dispose();
    }
}
