<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;

/**
 * Allows pooling of connections for stateless protocols.
 */
interface SocketPool
{
    /**
     * Checkout a socket from the specified URI authority.
     *
     * The resulting socket resource should be checked back in via `SocketPool::checkin()` once the calling code is
     * finished with the stream (even if the socket has been closed). Failure to checkin sockets will result in memory
     * leaks and socket queue blockage. Instead of checking the socket in again, it can also be cleared to prevent
     * re-use.
     *
     * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present. An
     *     optional fragment component can be used to differentiate different socket groups connected to the same URI.
     *     Connections to the same host with a different ConnectContext must use separate socket groups internally to
     *     prevent TLS negotiation with the wrong peer name or other TLS settings.
     * @param ConnectContext|null $context Socket connect context to use when connecting.
     * @param Cancellation|null $cancellation Optional cancellation token to cancel the checkout request.
     *
     * @return Socket Resolves to an Socket instance once a connection is available.
     *
     * @throws SocketException
     * @throws CancelledException
     */
    public function checkout(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket;

    /**
     * Return a previously checked-out socket to the pool, so it can be reused.
     *
     * @param Socket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function checkin(Socket $socket): void;

    /**
     * Remove the specified socket from the pool.
     *
     * @param Socket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function clear(Socket $socket): void;
}
