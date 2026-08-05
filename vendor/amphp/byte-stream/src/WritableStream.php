<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\Closable;

/**
 * A `WritableStream` allows writing data in chunks. Writers can wait on the returned promises to feel the backpressure.
 */
interface WritableStream extends Closable
{
    /**
     * Writes data to the stream.
     *
     * @param string $bytes Bytes to write.
     *
     * @throws ClosedException If the stream has already been closed.
     * @throws StreamException If writing to the stream fails.
     */
    public function write(string $bytes): void;

    /**
     * Marks the stream as no longer writable.
     *
     * Note that this is not the same as forcefully closing the stream. This method waits for all pending writes to
     * complete before closing the stream. Socket streams implementing this interface should only close the writable
     * side of the stream.
     *
     * @throws ClosedException If the stream has already been closed.
     * @throws StreamException If writing to the stream fails.
     */
    public function end(): void;

    /**
     * @return bool A stream may no longer be writable if it is closed or ended using {@see end()}.
     */
    public function isWritable(): bool;
}
