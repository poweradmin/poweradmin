<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

/**
 * Common interface for exceptions thrown when Task::run() throws an exception when being executed in a worker.
 *
 * @psalm-type FlattenedTrace = list<array<non-empty-string, scalar|list<scalar>>>
 */
interface TaskFailureThrowable extends \Throwable
{
    /**
     * @return string Original exception class name.
     */
    public function getOriginalClassName(): string;

    /**
     * @return string Original exception message.
     */
    public function getOriginalMessage(): string;

    /**
     * @return int|string Original exception code.
     */
    public function getOriginalCode(): string|int;

    /**
     * @return string Original exception file from which it was thrown.
     */
    public function getOriginalFile(): string;

    /**
     * @return int Original exception line from which it was thrown.
     */
    public function getOriginalLine(): int;

    /**
     * Returns the original exception stack trace.
     *
     * @return FlattenedTrace Same as {@see Throwable::getTrace()}, except all function arguments are formatted
     *      as strings. See {@see formatFlattenedBacktrace()}.
     */
    public function getOriginalTrace(): array;

    /**
     * Original backtrace flattened to a human-readable string.
     */
    public function getOriginalTraceAsString(): string;
}
