<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class WritableBuffer implements WritableStream
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $deferredFuture;

    private string $contents = '';

    private bool $closed = false;

    public function __construct()
    {
        $this->deferredFuture = new DeferredFuture;
    }

    public function write(string $bytes): void
    {
        if ($this->closed) {
            throw new ClosedException("The stream has already been closed");
        }

        $this->contents .= $bytes;
    }

    public function end(): void
    {
        if ($this->closed) {
            throw new ClosedException("The stream has already been closed");
        }

        $this->close();
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function buffer(): string
    {
        return $this->deferredFuture->getFuture()->await();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->deferredFuture->complete($this->contents);
        $this->contents = '';
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->deferredFuture->getFuture()->finally($onClose);
    }
}
