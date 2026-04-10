<?php declare(strict_types=1);

namespace Amp\Sync\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;

/**
 * Creates a Channel from a ConcurrentIterator and Queue. The ConcurrentIterator emits data to be received on the
 * channel (data emitted on the ConcurrentIterator will be returned from calls to {@see Channel::receive()}).
 * The Queue will receive data that sent on the channel (data passed to {@see Channel::send()} will be passed to
 * {@see Queue::push()}).
 *
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 *
 * @internal
 */
final class ConcurrentIteratorChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onClose;

    /**
     * @param ConcurrentIterator<TReceive> $receive
     * @param Queue<TSend> $send
     */
    public function __construct(
        private readonly ConcurrentIterator $receive,
        private readonly Queue $send,
    ) {
        $this->onClose = new DeferredFuture();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isClosed(): bool
    {
        return $this->send->isComplete();
    }

    public function close(): void
    {
        if (!$this->send->isComplete()) {
            $this->send->complete();
        }

        $this->receive->dispose();

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if (!$this->receive->continue($cancellation)) {
            $this->close();
            throw new ChannelException("The channel closed while waiting to receive the next value");
        }

        return $this->receive->getValue();
    }

    public function send(mixed $data): void
    {
        if ($data === null) {
            throw new ChannelException("Cannot send null on a channel");
        }

        if ($this->send->isComplete()) {
            throw new ChannelException("Cannot send on a closed channel");
        }

        $this->send->push($data);
    }
}
