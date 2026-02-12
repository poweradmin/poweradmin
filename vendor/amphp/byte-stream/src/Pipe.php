<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Create a local stream where data written to the pipe is immediately available on the pipe.
 *
 * Primarily useful for testing.
 */
final class Pipe
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly WritableStream $sink;

    private readonly ReadableStream $source;

    public function __construct(int $bufferSize)
    {
        $this->sink = new WritableIterableStream($bufferSize);
        $this->source = new ReadableIterableStream($this->sink->getIterator());
    }

    /**
     * @return ReadableStream Data written to the WritableStream returned by {@see getSink()} will be readable
     * on this stream.
     */
    public function getSource(): ReadableStream
    {
        return $this->source;
    }

    /**
     * @return WritableStream Data written to this stream will be readable by the stream returned from
     * {@see getSource()}.
     */
    public function getSink(): WritableStream
    {
        return $this->sink;
    }
}
