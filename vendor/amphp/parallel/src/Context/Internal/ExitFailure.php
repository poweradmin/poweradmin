<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextPanicError;
use function Amp\Parallel\Context\flattenThrowableBacktrace;

/**
 * @internal
 * @psalm-import-type FlattenedTrace from ContextPanicError
 * @template-implements ExitResult<never>
 */
final class ExitFailure implements ExitResult
{
    /** @var class-string<\Throwable> */
    private readonly string $className;

    private readonly string $message;

    private readonly int|string $code;

    private readonly string $file;

    private readonly int $line;

    /** @var FlattenedTrace */
    private readonly array $trace;

    private readonly ?self $previous;

    public function __construct(\Throwable $exception)
    {
        $this->className = \get_class($exception);
        $this->message = $exception->getMessage();
        $this->code = $exception->getCode();
        $this->file = $exception->getFile();
        $this->line = $exception->getLine();
        $this->trace = flattenThrowableBacktrace($exception);

        $previous = $exception->getPrevious();
        $this->previous = $previous ? new self($previous) : null;
    }

    /**
     * @throws ContextException
     */
    public function getResult(): never
    {
        $exception = $this->createException();

        throw new ContextException(
            'Process exited with an uncaught exception: ' . $exception->getMessage(),
            previous: $exception,
        );
    }

    private function createException(): ContextPanicError
    {
        $previous = $this->previous?->createException();

        return new ContextPanicError(
            $this->className,
            $this->message,
            $this->code,
            $this->file,
            $this->line,
            $this->trace,
            $previous,
        );
    }
}
