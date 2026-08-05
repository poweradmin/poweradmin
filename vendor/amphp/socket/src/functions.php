<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use League\Uri\UriString;
use Revolt\EventLoop;

/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param SocketAddress|string $address URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param BindContext|null $bindContext Context options for listening.
 * @param positive-int $chunkSize Chunk size for the accepted sockets.
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function listen(
    SocketAddress|string $address,
    ?BindContext $bindContext = null,
    int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE
): ResourceServerSocket {
    return (new ResourceServerSocketFactory($chunkSize))->listen($address, $bindContext);
}

/**
 * Create a new Datagram (UDP server) on the specified server address.
 *
 * @param InternetAddress|string $address URI in scheme://host:port format. UDP is assumed if no scheme is present.
 * @param BindContext|null $bindContext Context options for listening.
 * @param positive-int $limit Maximum size for received messages.
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function bindUdpSocket(
    InternetAddress|string $address,
    ?BindContext $bindContext = null,
    int $limit = ResourceUdpSocket::DEFAULT_LIMIT
): ResourceUdpSocket {
    $bindContext = $bindContext ?? new BindContext;

    $uri = (string) $address;
    $uri = match (\strstr($uri, '://', true)) {
        'udp' => $uri,
        false => 'udp://' . $uri,
        default => throw new \ValueError('Only udp scheme allowed for datagram creation; got ' . $uri),
    };

    $streamContext = \stream_context_create($bindContext->toStreamContextArray());

    \set_error_handler(static fn () => true); // Error checked after call to stream_socket_server().

    try {
        $server = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $streamContext);
    } finally {
        \restore_error_handler();
    }

    if (!$server || $errno) {
        throw new SocketException(
            \sprintf('Could not create datagram %s: [Error: #%d] %s', $uri, $errno, $errstr),
            $errno
        );
    }

    return new ResourceUdpSocket($server, $limit);
}

/**
 * Set or access the global SocketConnector instance.
 */
function socketConnector(?SocketConnector $connector = null): SocketConnector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    return $map[$driver] ??= new RetrySocketConnector(new DnsSocketConnector());
}

/**
 * Establish a socket connection to the specified URI.
 *
 * @param SocketAddress|string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null $context Socket connect context to use when connecting.
 *
 * @throws ConnectException
 * @throws CancelledException
 */
function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
{
    return socketConnector()->connect($uri, $context, $cancellation);
}

/**
 * Establish a socket connection to the specified URI and enable TLS.
 *
 * @param SocketAddress|string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null $context Socket connect context to use when connecting.
 *
 * @throws ConnectException
 * @throws TlsException
 * @throws CancelledException
 */
function connectTls(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
{
    $context ??= new ConnectContext();
    $tlsContext = $context->getTlsContext() ?? new ClientTlsContext('');

    if ($tlsContext->getPeerName() === '') {
        $hostname = '';
        $uriString = (string) $uri;
        if (\str_contains($uriString, 'tcp://')) {
            $hostname = UriString::parse($uriString)['host'] ?? '';
        } elseif (!\str_contains($uriString, '://')) {
            $hostname = UriString::parse('tcp://' . $uriString)['host'] ?? '';
        }

        $tlsContext = $tlsContext->withPeerName($hostname);
    }

    $socket = socketConnector()->connect($uri, $context->withTlsContext($tlsContext), $cancellation);
    $socket->setupTls($cancellation);

    return $socket;
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @param positive-int $chunkSize
 *
 * @return array{ResourceSocket, ResourceSocket} Pair of socket resources.
 *
 * @throws SocketException If creating the sockets fails.
 */
function createSocketPair(int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE): array
{
    try {
        \set_error_handler(static function (int $errno, string $errstr): void {
            throw new SocketException(\sprintf('Failed to create socket pair.  Errno: %d; %s', $errno, $errstr));
        });

        $sockets = \stream_socket_pair(
            \PHP_OS_FAMILY === 'Windows' ? \STREAM_PF_INET : \STREAM_PF_UNIX,
            \STREAM_SOCK_STREAM,
            \STREAM_IPPROTO_IP,
        );
        if ($sockets === false) {
            throw new SocketException('Failed to create socket pair.');
        }
    } finally {
        \restore_error_handler();
    }

    return [
        ResourceSocket::fromClientSocket($sockets[0], chunkSize: $chunkSize),
        ResourceSocket::fromClientSocket($sockets[1], chunkSize: $chunkSize),
    ];
}

/**
 * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
 */
function hasTlsAlpnSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10002000;
}

function hasTlsSecurityLevelSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10100000;
}
