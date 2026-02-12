<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class ReadableStreamChain implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    /** @var ReadableStream[] */
    private array $sources;

    private bool $reading = false;

    private readonly DeferredFuture $onClose;

    public function __construct(ReadableStream ...$sources)
    {
        $this->sources = $sources;
        $this->onClose = new DeferredFuture;

        if (empty($this->sources)) {
            $this->close();
        }
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->reading) {
            throw new PendingReadError;
        }

        if (!$this->sources) {
            return null;
        }

        $this->reading = true;

        try {
            while ($this->sources) {
                $chunk = $this->sources[0]->read($cancellation);
                if ($chunk === null) {
                    \array_shift($this->sources);
                    continue;
                }

                return $chunk;
            }

            return null;
        } finally {
            $this->reading = false;
        }
    }

    public function isReadable(): bool
    {
        return !empty($this->sources);
    }

    public function close(): void
    {
        $sources = $this->sources;
        $this->sources = [];

        foreach ($sources as $source) {
            $source->close();
        }

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
