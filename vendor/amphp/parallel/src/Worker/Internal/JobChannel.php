<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;

/**
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 *
 * @internal
 */
final class JobChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onClose;

    public function __construct(
        private readonly string $id,
        private readonly Channel $channel,
        private readonly ConcurrentIterator $iterator,
    ) {
        $this->onClose = new DeferredFuture();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function send(mixed $data): void
    {
        if ($this->onClose->isComplete()) {
            throw new ChannelException('Channel has already been closed.');
        }

        $this->channel->send(new JobMessage($this->id, $data));
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if (!$this->iterator->continue($cancellation)) {
            $this->close();
            throw new ChannelException('Channel source closed unexpectedly');
        }

        return $this->iterator->getValue();
    }

    public function close(): void
    {
        $this->iterator->dispose();

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed() || $this->onClose->isComplete();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }
}
