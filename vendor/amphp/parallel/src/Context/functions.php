<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\Cancellation;
use Revolt\EventLoop;
use function Amp\Serialization\encodeUnprintableChars;

/**
 * @template TResult
 * @template TReceive
 * @template TSend
 *
 * @param string|non-empty-list<string> $script Path to PHP script or array with first element as path and following
 *     elements as options to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
 *
 * @return Context<TResult, TReceive, TSend>
 */
function startContext(string|array $script, ?Cancellation $cancellation = null): Context
{
    return contextFactory()->start($script, $cancellation);
}

/**
 * Gets or sets the global context factory.
 */
function contextFactory(?ContextFactory $factory = null): ContextFactory
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($factory) {
        return $map[$driver] = $factory;
    }

    return $map[$driver] ??= new DefaultContextFactory();
}

/**
 * @psalm-type FlattenedTraceEntry = array<non-empty-string, scalar|list<scalar>>
 *
 * @return list<FlattenedTraceEntry> Serializable exception backtrace, with all function
 *      arguments flattened to strings.
 */
function flattenThrowableBacktrace(\Throwable $exception): array
{
    return \array_map(function (array $call): array {
        /** @psalm-suppress InvalidArrayOffset */
        unset($call['object']);
        $call['args'] = \array_map(flattenArgument(...), $call['args'] ?? []);

        /** @var FlattenedTraceEntry $call */
        return $call;
    }, $exception->getTrace());
}

/**
 * @param array $trace Backtrace produced by {@see flattenThrowableBacktrace()}.
 */
function formatFlattenedBacktrace(array $trace): string
{
    $output = [];

    foreach ($trace as $index => $call) {
        if (isset($call['class'])) {
            $name = $call['class'] . $call['type'] . $call['function'];
        } else {
            $name = $call['function'];
        }

        $output[] = \sprintf(
            '#%d %s(%d): %s(%s)',
            $index,
            $call['file'] ?? '[internal function]',
            $call['line'] ?? 0,
            $name,
            \implode(', ', $call['args'] ?? [])
        );
    }

    return \implode("\n", $output);
}

/**
 * @return string Serializable string representation of $value for backtraces.
 */
function flattenArgument(mixed $value): string
{
    if ($value instanceof \Closure) {
        $closureReflection = new \ReflectionFunction($value);
        return \sprintf(
            'Closure(%s:%s)',
            $closureReflection->getFileName(),
            $closureReflection->getStartLine()
        );
    }

    if (\is_object($value)) {
        return \sprintf('Object(%s)', \get_class($value));
    }

    if (\is_array($value)) {
        $length = \count($value);
        if ($length > 5) {
            $value = \array_slice($value, 0, 5);
        }
        $value = \implode(', ', \array_map(flattenArgument(...), $value));
        return 'Array([' . $value . ($length > 5 ? ', ...' : '') . '])';
    }

    if (\is_resource($value)) {
        return \sprintf('Resource(%s)', \get_resource_type($value));
    }

    if (\is_string($value)) {
        if (\strlen($value) > 30) {
            $value = \substr($value, 0, 27) . '...';
        }
        return '"' . encodeUnprintableChars($value) . '"';
    }

    if (\is_null($value)) {
        return 'null';
    }

    if (\is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return (string) $value;
}
