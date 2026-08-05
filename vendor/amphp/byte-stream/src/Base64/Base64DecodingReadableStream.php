<?php declare(strict_types=1);

namespace Amp\ByteStream\Base64;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class Base64DecodingReadableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    private string $buffer = '';

    public function __construct(
        private readonly ReadableStream $source,
    ) {
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->source->isClosed()) {
            throw new StreamException('Failed to read stream chunk due to invalid base64 data');
        }

        $chunk = $this->source->read($cancellation);
        if ($chunk === null) {
            if ($this->buffer === '') {
                return null;
            }

            $chunk = \base64_decode($this->buffer, true);
            $this->buffer = '';

            if ($chunk === false) {
                $this->source->close();
                throw new StreamException('Failed to read stream chunk due to invalid base64 data');
            }

            return $chunk;
        }

        $this->buffer .= $chunk;

        $length = \strlen($this->buffer);
        $chunk = \base64_decode(\substr($this->buffer, 0, $length - $length % 4), true);

        if ($chunk === false) {
            $this->source->close();
            $this->buffer = '';

            throw new StreamException('Failed to read stream chunk due to invalid base64 data');
        }

        $this->buffer = \substr($this->buffer, $length - $length % 4);

        return $chunk;
    }

    public function isReadable(): bool
    {
        return $this->source->isReadable();
    }

    public function close(): void
    {
        $this->source->close();
    }

    public function isClosed(): bool
    {
        return $this->source->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }
}
