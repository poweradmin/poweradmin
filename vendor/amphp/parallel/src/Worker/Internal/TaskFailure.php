<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\Parallel\Worker\TaskFailureThrowable;
use function Amp\Parallel\Context\flattenThrowableBacktrace;

/**
 * @internal
 * @psalm-import-type FlattenedTrace from TaskFailureThrowable
 * @template-extends TaskResult<never>
 */
class TaskFailure extends TaskResult
{
    /** @var class-string<\Throwable> */
    private readonly string $className;

    private readonly TaskExceptionType $type;

    private readonly string $message;

    private readonly string|int $code;

    private readonly string $file;

    private readonly int $line;

    /** @var FlattenedTrace */
    private readonly array $trace;

    private readonly ?self $previous;

    public function __construct(string $id, \Throwable $exception)
    {
        parent::__construct($id);
        $this->className = \get_class($exception);
        $this->type = TaskExceptionType::fromException($exception);
        $this->message = $exception->getMessage();
        $this->code = $exception->getCode();
        $this->file = $exception->getFile();
        $this->line = $exception->getLine();
        $this->trace = flattenThrowableBacktrace($exception);

        $previous = $exception->getPrevious();
        $this->previous = $previous ? new self($id, $previous) : null;
    }

    /**
     * @throws TaskFailureThrowable
     */
    public function getResult(): never
    {
        throw $this->createException();
    }

    final protected function createException(): TaskFailureThrowable
    {
        return $this->type->createException(
            $this->className,
            $this->message,
            $this->code,
            $this->file,
            $this->line,
            $this->trace,
            $this->previous?->createException(),
        );
    }
}
