<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;

interface Socket extends ReadableStream, WritableStream
{
    /**
     * @param positive-int|null $limit Read at most $limit bytes from the socket. {@code null} uses an implementation
     *     defined limit.
     */
    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string;

    public function getLocalAddress(): SocketAddress;

    public function getRemoteAddress(): SocketAddress;

    /**
     * @return void Returns when TLS is successfully set up on the socket.
     *
     * @throws SocketException Socket is closed if setting up TLS fails.
     */
    public function setupTls(?Cancellation $cancellation = null): void;

    /**
     * @return void Returns when TLS is successfully shutdown.
     *
     * @throws SocketException Socket is closed if shutting down TLS fails.
     */
    public function shutdownTls(?Cancellation $cancellation = null): void;

    public function isTlsConfigurationAvailable(): bool;

    public function getTlsState(): TlsState;

    /**
     * @return TlsInfo|null The TLS (crypto) context info if TLS is enabled on the socket or null otherwise.
     */
    public function getTlsInfo(): ?TlsInfo;
}
