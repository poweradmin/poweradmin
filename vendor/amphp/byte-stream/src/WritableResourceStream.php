<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * Output stream abstraction for PHP's stream resources.
 */
final class WritableResourceStream implements WritableStream, ResourceStream
{
    use ForbidCloning;
    use ForbidSerialization;

    private const LARGE_CHUNK_SIZE = 128 * 1024;

    /** @var resource|null */
    private $resource;

    private string $callbackId;

    /** @var \SplQueue<array{string, Suspension|null}> */
    private readonly \SplQueue $writes;

    private bool $writable = true;

    /** @var positive-int|null */
    private ?int $chunkSize = null;

    /** @var \Closure():bool */
    private readonly \Closure $errorHandler;

    private readonly DeferredFuture $onClose;

    /**
     * @param resource $stream Stream resource.
     * @param positive-int|null $chunkSize Chunk size per `fwrite()` operation.
     */
    public function __construct($stream, ?int $chunkSize = null)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new \Error("Expected a valid stream");
        }

        $meta = \stream_get_meta_data($stream);

        if (\str_contains($meta["mode"], "r") && !\str_contains($meta["mode"], "+")) {
            throw new \Error("Expected a writable stream");
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($chunkSize !== null && $chunkSize <= 0) {
            throw new \ValueError('The chunk length must be a positive integer');
        }

        $this->onClose = $onClose = new DeferredFuture;

        \stream_set_blocking($stream, false);
        \stream_set_write_buffer($stream, 0);

        // Ignore any errors raised while this handler is set. Errors will be checked through return values.
        $this->errorHandler = static fn () => true;

        $this->resource = $stream;
        $this->chunkSize = &$chunkSize;

        $writes = $this->writes = new \SplQueue;
        $writable = &$this->writable;
        $resource = &$this->resource;

        $this->callbackId = EventLoop::disable(EventLoop::onWritable(
            $this->resource,
            static function ($callbackId) use (
                $writes,
                &$chunkSize,
                &$writable,
                &$resource,
                $onClose,
            ): void {
                $firstWrite = true;

                try {
                    while (!$writes->isEmpty()) {
                        /** @var Suspension|null $suspension */
                        [$data, $suspension] = $writes->shift();
                        $length = \strlen($data);

                        if ($length === 0) {
                            $suspension?->resume();
                            continue;
                        }

                        if (!$writable) {
                            $suspension?->resume(static fn () => throw new ClosedException("The stream was closed"));
                            continue;
                        }

                        /** @psalm-suppress TypeDoesNotContainType */
                        if (!\is_resource($resource)) {
                            $writable = false;
                            $suspension?->resume(static fn () => throw new ClosedException("The stream was closed by the peer"));
                            continue;
                        }

                        // Using error handler to verify that writing zero bytes was not due an error.
                        // @see https://github.com/reactphp/stream/pull/150
                        $errorCode = 0;
                        $errorMessage = 'Unknown error';
                        \set_error_handler(static function (int $errno, string $message) use (&$errorCode, &$errorMessage): bool {
                            $errorCode = $errno;
                            $errorMessage = $message;

                            return true;
                        });

                        try {
                            // Customer error handler needed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                            // Use conditional, because PHP doesn't like getting null passed
                            if ($chunkSize) {
                                $written = \fwrite($resource, $data, $chunkSize);
                            } else {
                                $written = \fwrite($resource, $data);
                            }
                        } finally {
                            \restore_error_handler();
                        }

                        $written = (int) $written; // Cast potential false to 0.

                        // Broken pipes between processes on macOS/FreeBSD do not detect EOF properly.
                        // fwrite() may write zero bytes on subsequent calls due to the buffer filling again.
                        /** @psalm-suppress TypeDoesNotContainType $errorCode may be set by error handler. */
                        if ($written === 0 && $errorCode !== 0 && $firstWrite) {
                            $writable = false;
                            $suspension?->resume(static fn () => throw new StreamException(
                                \sprintf('Failed to write to stream (%d): %s', $errorCode, $errorMessage)
                            ));

                            continue;
                        }

                        if ($length > $written) {
                            $data = \substr($data, $written);
                            $writes->unshift([$data, $suspension]);
                            return;
                        }

                        $suspension?->resume();
                        $firstWrite = false;
                    }
                } finally {
                    /** @psalm-suppress RedundantCondition */
                    if (!$writable && \is_resource($resource)) {
                        $meta = \stream_get_meta_data($resource);
                        if (\str_contains($meta["mode"], "+")) {
                            \stream_socket_shutdown($resource, \STREAM_SHUT_WR);
                        } else {
                            \fclose($resource);
                        }
                        $resource = null;
                    }

                    if ($writes->isEmpty()) {
                        if ($writable) {
                            EventLoop::disable($callbackId);
                        } else {
                            EventLoop::cancel($callbackId);

                            if (!$onClose->isComplete()) {
                                $onClose->complete();
                            }
                        }
                    }
                }
            }
        ));
    }

    /**
     * Writes data to the stream.
     *
     * @param string $bytes Bytes to write.
     *
     * @throws ClosedException If the stream has already been closed.
     */
    public function write(string $bytes): void
    {
        if (!$this->writable) {
            throw new ClosedException("The stream is not writable");
        }

        $length = \strlen($bytes);
        $written = 0;

        if ($this->writes->isEmpty()) {
            if ($length === 0) {
                return;
            }

            if (!\is_resource($this->resource)) {
                throw new ClosedException("The stream was closed by the peer");
            }

            \set_error_handler($this->errorHandler);

            try {
                // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                // Use conditional, because PHP doesn't like getting null passed.
                if ($this->chunkSize) {
                    $written = \fwrite($this->resource, $bytes, $this->chunkSize);
                } else {
                    $written = \fwrite($this->resource, $bytes);
                }
            } finally {
                \restore_error_handler();
            }

            $written = (int) $written; // Cast potential false to 0.

            if ($length === $written) {
                return;
            }

            if ($written > 0) {
                $bytes = \substr($bytes, $written);
            }
        }

        if ($length - $written > self::LARGE_CHUNK_SIZE) {
            $chunks = \str_split($bytes, self::LARGE_CHUNK_SIZE);

            /** @var string $data */
            $bytes = \array_pop($chunks);

            foreach ($chunks as $chunk) {
                $this->writes->push([$chunk, null]);
            }
        }

        EventLoop::enable($this->callbackId);
        $this->writes->push([$bytes, $suspension = EventLoop::getSuspension()]);

        if ($closure = $suspension->suspend()) {
            $closure();
        }
    }

    /**
     * Closes the stream after all pending writes have been completed. Optionally writes a final data chunk before.
     */
    public function end(): void
    {
        $this->writable = false;

        if ($this->writes->isEmpty()) {
            $this->close();
        }
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * Closes the stream forcefully. Multiple `close()` calls are ignored.
     */
    public function close(): void
    {
        if (\is_resource($this->resource) && \get_resource_type($this->resource) === 'stream') {
            // Error suppression, as resource might already be closed
            $meta = \stream_get_meta_data($this->resource);

            if (\str_contains($meta["mode"], "+")) {
                \stream_socket_shutdown($this->resource, \STREAM_SHUT_WR);
            } else {
                /** @psalm-suppress InvalidPropertyAssignmentValue psalm reports this as closed-resource */
                \fclose($this->resource);
            }
        }

        $this->free();
    }

    public function isClosed(): bool
    {
        return $this->resource === null;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    /**
     * @return resource|object|null Stream resource or null if end() has been called or the stream closed.
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param positive-int $chunkSize
     */
    public function setChunkSize(int $chunkSize): void
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($chunkSize <= 0) {
            throw new \ValueError('The chunk length must be a positive integer');
        }

        $this->chunkSize = $chunkSize;
    }

    public function __destruct()
    {
        $this->free();
    }

    /**
     * References the writable watcher, so the loop keeps running in case there's a pending write.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void
    {
        if (!$this->resource) {
            return;
        }

        EventLoop::reference($this->callbackId);
    }

    /**
     * Unreferences the writable watcher, so the loop doesn't keep running even if there are pending writes.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void
    {
        if (!$this->resource) {
            return;
        }

        EventLoop::unreference($this->callbackId);
    }

    /**
     * Nulls reference to resource, marks stream unwritable, and fails any pending write.
     */
    private function free(): void
    {
        if ($this->resource === null) {
            return;
        }

        $this->resource = null;
        $this->writable = false;

        if (!$this->writes->isEmpty()) {
            $exception = new ClosedException("The socket was closed before writing completed");
            do {
                /** @var Suspension|null $suspension */
                [, $suspension] = $this->writes->shift();
                $suspension?->throw($exception);
            } while (!$this->writes->isEmpty());
        }

        EventLoop::cancel($this->callbackId);

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }
}
