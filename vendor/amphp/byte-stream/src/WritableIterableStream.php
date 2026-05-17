<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\Queue;

/**
 * @template-implements \IteratorAggregate<int, string>
 */
final class WritableIterableStream implements WritableStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly Queue $queue;

    /** @var \Traversable<int, string> */
    private readonly iterable $iterable;

    private int $bufferSize;

    private readonly DeferredFuture $onClose;

    public function __construct(int $bufferSize)
    {
        $this->queue = new Queue;
        $this->iterable = $this->queue->iterate();
        $this->bufferSize = $bufferSize;
        $this->onClose = new DeferredFuture;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (!$this->queue->isComplete()) {
            $this->queue->complete();
        }

        if ($this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return !$this->isWritable();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function write(string $bytes): void
    {
        if ($this->queue->isComplete() || $this->queue->isDisposed()) {
            throw new ClosedException('The stream is no longer writable');
        }

        $length = \strlen($bytes);
        $this->bufferSize -= $length;

        $future = $this->queue->pushAsync($bytes)->finally(fn () => $this->bufferSize += $length);

        if ($this->bufferSize < 0) {
            $future->await();
        } else {
            $future->ignore();
        }
    }

    public function end(): void
    {
        if (!$this->queue->isComplete()) {
            $this->queue->complete();
        }
    }

    public function isWritable(): bool
    {
        return !$this->queue->isComplete() && !$this->queue->isDisposed();
    }

    public function getIterator(): \Traversable
    {
        return $this->iterable;
    }
}
