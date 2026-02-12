<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Pipeline;

/**
 * Creates a stream from an iterable emitting strings. If the iterable throws an exception, the exception will
 * be thrown from {@see read()} and {@see buffer()}. Consider wrapping any exceptions in {@see StreamException}
 * if you do not wish for another type of exception to be thrown from the stream.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ReadableIterableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    /** @var ConcurrentIterator<string>|null */
    private ?ConcurrentIterator $iterator;

    private ?\Throwable $exception = null;

    private bool $pending = false;

    private readonly DeferredFuture $onClose;

    /**
     * @param iterable<mixed, string> $iterable
     */
    public function __construct(iterable $iterable)
    {
        $this->iterator = $iterable instanceof ConcurrentIterator
            ? $iterable
            : Pipeline::fromIterable($iterable)->getIterator();

        $this->onClose = new DeferredFuture;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->exception) {
            throw $this->exception;
        }

        if ($this->pending) {
            throw new PendingReadError;
        }

        if ($this->iterator === null) {
            return null;
        }

        $this->pending = true;

        try {
            if (!$this->iterator->continue($cancellation)) {
                $this->iterator = null;
                return null;
            }

            $chunk = $this->iterator->getValue();

            if (!\is_string($chunk)) {
                throw new StreamException(\sprintf(
                    "Unexpected iterable value of type %s, expected string",
                    \get_debug_type($chunk)
                ));
            }

            return $chunk;
        } catch (\Throwable $exception) {
            if ($exception instanceof CancelledException && $cancellation?->isRequested()) {
                throw $exception; // Read cancelled, stream did not fail.
            }

            if ($exception instanceof DisposedException) {
                $exception = new ClosedException('Stream manually closed', previous: $exception);
            }

            throw $this->exception = $exception;
        } finally {
            $this->pending = false;
        }
    }

    public function isReadable(): bool
    {
        return $this->iterator !== null;
    }

    public function close(): void
    {
        $this->iterator?->dispose();
        $this->iterator = null;

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return !$this->isReadable();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }
}
