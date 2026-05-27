<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\ByteStream\Internal\ChannelParser;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\Serializer;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Amp\Sync\LocalMutex;
use Amp\Sync\Mutex;
use function Amp\async;

/**
 * An asynchronous channel for sending data between threads and processes.
 *
 * Supports full duplex read and write.
 *
 * @template TReceive
 * @template TSend
 * @template-implements Channel<TReceive, TSend>
 */
final class StreamChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly ChannelParser $parser;

    /** @var \SplQueue<TReceive> */
    private readonly \SplQueue $received;

    private readonly Mutex $readMutex;

    /**
     * Creates a new channel from the given stream objects. Note that $read and $write can be the same object.
     */
    public function __construct(
        private readonly ReadableStream $read,
        private readonly WritableStream $write,
        ?Serializer $serializer = null,
    ) {
        $this->received = new \SplQueue();
        $this->readMutex = new LocalMutex();
        $this->parser = new ChannelParser($this->received->push(...), $serializer);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Closes the read and write resource streams.
     */
    public function close(): void
    {
        $this->read->close();
        $this->write->close();
    }

    public function send(mixed $data): void
    {
        $data = $this->parser->encode($data);

        try {
            $this->write->write($data);
        } catch (\Throwable $exception) {
            throw new ChannelException("Sending on the channel failed. Did the context die?", 0, $exception);
        }
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfRequested();

        $lock = $this->readMutex->acquire();

        try {
            while ($this->received->isEmpty()) {
                try {
                    $chunk = $this->read->read($cancellation);
                } catch (StreamException $exception) {
                    throw new ChannelException(
                        "Reading from the channel failed. Did the context die?",
                        0,
                        $exception,
                    );
                }

                if ($chunk === null) {
                    throw new ChannelException("The channel closed while waiting to receive the next value");
                }

                $this->parser->push($chunk);
            }

            return $this->received->shift();
        } finally {
            async($lock->release(...));
        }
    }

    public function isClosed(): bool
    {
        return $this->read->isClosed() || $this->write->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->read->onClose($onClose);
    }
}
