<?php declare(strict_types=1);

namespace Amp\ByteStream\Base64;

use Amp\ByteStream\WritableStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class Base64EncodingWritableStream implements WritableStream
{
    use ForbidCloning;
    use ForbidSerialization;

    private string $buffer = '';

    public function __construct(
        private readonly WritableStream $destination,
    ) {
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;

        $length = \strlen($this->buffer);
        $chunk = \base64_encode(\substr($this->buffer, 0, $length - $length % 3));
        $this->buffer = \substr($this->buffer, $length - $length % 3);

        $this->destination->write($chunk);
    }

    public function end(): void
    {
        $chunk = \base64_encode($this->buffer);
        $this->buffer = '';

        $this->destination->write($chunk);
        $this->destination->end();
    }

    public function isWritable(): bool
    {
        return $this->destination->isWritable();
    }

    public function close(): void
    {
        $this->destination->close();
    }

    public function isClosed(): bool
    {
        return $this->destination->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->destination->onClose($onClose);
    }
}
