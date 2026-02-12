<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class ResourceServerSocket implements ServerSocket, ResourceStream
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var resource|null Stream socket server resource. */
    private $socket;

    private readonly string $callbackId;

    private readonly SocketAddress $address;

    private ?Suspension $acceptor = null;

    /** @var \Closure(CancelledException):void */
    private readonly \Closure $cancel;

    private readonly \Closure $errorHandler;

    private readonly DeferredFuture $onClose;

    /**
     * @param resource $socket A bound socket server resource
     * @param positive-int $chunkSize Chunk size for the input and output stream.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct(
        $socket,
        private readonly BindContext $bindContext,
        private readonly int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
    ) {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->address = SocketAddress\fromResourceLocal($socket);

        // Ignore any errors raised while this handler is set. Errors will be checked through return values.
        $this->errorHandler = static fn () => true;

        $this->onClose = new DeferredFuture;

        \stream_set_blocking($this->socket, false);

        $acceptor = &$this->acceptor;
        $this->callbackId = EventLoop::onReadable($this->socket, static function () use (&$acceptor): void {
            $acceptor?->resume(true);
            $acceptor = null;
        });

        $callbackId = $this->callbackId;
        $this->cancel = static function (CancelledException $exception) use (&$acceptor, $callbackId): void {
            EventLoop::disable($callbackId);

            $acceptor?->throw($exception);
            $acceptor = null;
        };

        EventLoop::disable($this->callbackId);
    }

    /**
     * Automatically cancels the loop watcher.
     */
    public function __destruct()
    {
        if (!$this->socket) {
            return;
        }

        $this->free();
    }

    private function free(): void
    {
        EventLoop::cancel($this->callbackId);

        $this->socket = null;

        $this->acceptor?->resume(false);
        $this->acceptor = null;

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?ResourceSocket
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->socket) {
            return null; // Resolve with null when server is closed.
        }

        if ($client = $this->acceptSocketClient()) {
            return ResourceSocket::fromServerSocket($client, $this->chunkSize);
        }

        EventLoop::enable($this->callbackId);

        $id = $cancellation?->subscribe($this->cancel);

        try {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            do {
                $this->acceptor = EventLoop::getSuspension();
                if (!$this->acceptor->suspend()) {
                    return null;
                }
            } while (!$client = $this->acceptSocketClient());

            /** @var resource $client Psalm 5.x seems to think $client is of type 'never' */
            return ResourceSocket::fromServerSocket($client, $this->chunkSize);
        } finally {
            EventLoop::disable($this->callbackId);

            /** @psalm-suppress PossiblyNullArgument $id is always defined if $cancellation is non-null */
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * @return resource|false
     */
    private function acceptSocketClient()
    {
        \assert($this->socket !== null, "Unexpected server state");

        \set_error_handler($this->errorHandler);

        try {
            return \stream_socket_accept($this->socket, 0); // Timeout of 0 to be non-blocking.
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void
    {
        if ($this->socket) {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            \fclose($this->socket);
        }

        $this->free();
    }

    public function isClosed(): bool
    {
        return $this->socket === null;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    /**
     * References the readability callback used for detecting new connection attempts in {@code accept()}.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::reference($this->callbackId);
    }

    /**
     * Unreferences the readability callback used for detecting new connection attempts in {@code accept()}.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::unreference($this->callbackId);
    }

    public function getAddress(): SocketAddress
    {
        return $this->address;
    }

    public function getBindContext(): BindContext
    {
        return $this->bindContext;
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->socket;
    }
}
