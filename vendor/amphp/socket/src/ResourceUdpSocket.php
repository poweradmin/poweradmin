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

final class ResourceUdpSocket implements UdpSocket, ResourceStream
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_LIMIT = 65507; // Max UDP payload size.

    /** @var resource|null UDP socket resource. */
    private $socket;

    private readonly string $callbackId;

    private readonly InternetAddress $address;

    private ?Suspension $reader = null;

    /** @var \Closure(CancelledException):void */
    private readonly \Closure $cancel;

    private int $limit;

    private int $defaultLimit;

    private readonly DeferredFuture $onClose;

    /**
     * @param resource $socket A bound udp socket resource.
     * @param positive-int $limit Maximum size for received messages.
     *
     * @throws \Error If a stream resource is not given for {@code $socket}.
     */
    public function __construct($socket, int $limit = self::DEFAULT_LIMIT)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($limit < 1) {
            throw new \ValueError('Invalid length limit of ' . $limit . ', must be greater than 0');
        }

        $socketAddress = SocketAddress\fromResourceLocal($socket);

        $this->socket = $socket;
        $this->defaultLimit = $this->limit = &$limit;
        $this->address = match ($socketAddress::class) {
            InternetAddress::class => $socketAddress,
            default => throw new \ValueError('Invalid socket address type: ' . $socketAddress::class)
        };

        $this->onClose = new DeferredFuture;

        \stream_set_blocking($this->socket, false);
        \stream_set_read_buffer($this->socket, 0);

        $reader = &$this->reader;
        $this->callbackId = EventLoop::onReadable($this->socket, static function (string $callbackId, $socket) use (
            &$reader,
            &$limit,
        ): void {
            static $errorHandler;

            \assert($reader !== null);

            \set_error_handler($errorHandler ??= static fn () => true);

            try {
                $data = \stream_socket_recvfrom($socket, $limit, 0, $address);
            } finally {
                \restore_error_handler();
            }

            /** @psalm-suppress TypeDoesNotContainType */
            if ($data === false) {
                EventLoop::cancel($callbackId);

                $reader->resume();
            } else {
                EventLoop::disable($callbackId);

                $reader->resume([SocketAddress\fromString($address), $data]);
            }

            $reader = null;
        });

        $callbackId = $this->callbackId;
        $this->cancel = static function (CancelledException $exception) use (&$reader, $callbackId): void {
            EventLoop::disable($callbackId);

            $reader?->throw($exception);
            $reader = null;
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

    /**
     * @param positive-int|null $limit If null, the default chunk size is used.
     *
     * @return null|array{InternetAddress, string}
     */
    public function receive(?Cancellation $cancellation = null, ?int $limit = null): ?array
    {
        if ($this->reader) {
            throw new PendingReceiveError;
        }

        $limit ??= $this->defaultLimit;

        if ($limit <= 0) {
            throw new \ValueError('The length limit must be a positive integer, got ' . $limit);
        }

        if (!$this->socket) {
            return null; // Resolve with null when endpoint is closed.
        }

        $this->limit = $limit;
        $this->reader = EventLoop::getSuspension();

        EventLoop::enable($this->callbackId);

        $id = $cancellation?->subscribe($this->cancel);

        try {
            return $this->reader->suspend();
        } finally {
            /** @psalm-suppress PossiblyNullArgument $id is always defined if $cancellation is present */
            $cancellation?->unsubscribe($id);
        }
    }

    public function send(InternetAddress $address, string $data): void
    {
        static $errorHandler;
        $errorHandler ??= static function (int $errno, string $errstr): never {
            throw new SocketException(\sprintf('Could not send datagram packet: %s', $errstr));
        };

        if (!$this->socket) {
            throw new SocketException('The datagram socket is not writable');
        }

        \set_error_handler($errorHandler);

        try {
            $result = \stream_socket_sendto($this->socket, $data, 0, $address->toString());
            /** @psalm-suppress TypeDoesNotContainType */
            if ($result < 0 || $result === false) {
                throw new SocketException('Could not send datagram packet: Unknown error');
            }
        } finally {
            \restore_error_handler();
        }
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

    /**
     * References the event loop callback used for being notified about available packets.
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
     * Unreferences the event loop callback used for being notified about available packets.
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

    /**
     * Closes the datagram socket and stops receiving data. A pending {@code receive()} will return {@code null}.
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

    public function getAddress(): InternetAddress
    {
        return $this->address;
    }

    /**
     * @param positive-int $limit The new default maximum packet size to receive.
     */
    public function setLimit(int $limit): void
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($limit <= 0) {
            throw new \ValueError('The chunk length must be a positive integer, got ' . $limit);
        }

        $this->defaultLimit = $limit;
    }

    private function free(): void
    {
        EventLoop::cancel($this->callbackId);

        $this->socket = null;

        $this->reader?->resume();
        $this->reader = null;

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }
}
