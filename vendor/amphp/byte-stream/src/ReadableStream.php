<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\Cancellation;
use Amp\Closable;

/**
 * A `ReadableStream` allows reading byte streams in chunks.
 *
 * **Example**
 *
 * ```php
 * function readAll(ReadableStream $source): string {
 *     $buffer = "";
 *
 *     while (null !== $chunk = $source->read()) {
 *         $buffer .= $chunk;
 *     }
 *
 *     return $buffer;
 * }
 * ```
 *
 * @extends \Traversable<int, string>
 */
interface ReadableStream extends Closable, \Traversable
{
    /**
     * Reads data from the stream.
     *
     * @param Cancellation|null $cancellation Cancel the read operation. The state in which the stream will be after
     * a cancelled operation is implementation dependent.
     *
     * @return string|null Returns a string when new data is available or {@code null} if the stream has closed.
     *
     * @throws PendingReadError Thrown if another read operation is still pending.
     * @throws StreamException If the stream contains invalid data, e.g. invalid compression
     */
    public function read(?Cancellation $cancellation = null): ?string;

    /**
     * @return bool A stream may become unreadable if the underlying source is closed or lost.
     */
    public function isReadable(): bool;
}
