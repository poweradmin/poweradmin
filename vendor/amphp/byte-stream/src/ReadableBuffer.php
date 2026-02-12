<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * ReadableStream with a single already known data chunk.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ReadableBuffer implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    private ?string $contents;

    private readonly DeferredFuture $onClose;

    /**
     * @param string|null $contents Data chunk or `null` for no data chunk.
     */
    public function __construct(?string $contents = null)
    {
        $this->contents = $contents === '' ? null : $contents;
        $this->onClose = new DeferredFuture;

        if ($this->contents === null) {
            $this->close();
        }
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $contents = $this->contents;
        $this->close();

        return $contents;
    }

    public function isReadable(): bool
    {
        return $this->contents !== null;
    }

    public function close(): void
    {
        $this->contents = null;
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
