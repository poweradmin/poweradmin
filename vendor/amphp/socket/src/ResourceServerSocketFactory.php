<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class ResourceServerSocketFactory implements ServerSocketFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param positive-int|null $chunkSize
     */
    public function __construct(private readonly ?int $chunkSize = null)
    {
    }

    /**
     * @throws SocketException
     */
    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ResourceServerSocket
    {
        $bindContext ??= new BindContext;

        if (\is_string($address)) {
            [$scheme, $host, $port] = Internal\parseUri($address);

            $address = match ($scheme) {
                'tcp' => new InternetAddress($host, $port),
                'unix' => new UnixAddress('/' . $host),
                default => throw new \ValueError('Invalid address: only tcp and unix schemes accepted; got ' . $address),
            };
        }

        $uri = match ($address->getType()) {
            SocketAddressType::Internet => 'tcp://' . $address->toString(),
            SocketAddressType::Unix => 'unix://' . $address->toString(),
        };

        $streamContext = \stream_context_create($bindContext->toStreamContextArray());

        \set_error_handler(static fn () => true); // Error checked after call to stream_socket_server().

        try {
            $server = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $streamContext);
        } finally {
            \restore_error_handler();
        }

        if (!$server || $errno) {
            throw new SocketException(\sprintf(
                'Could not create server %s: [Error: #%d] %s',
                $uri,
                $errno,
                $errstr
            ), $errno);
        }

        return new ResourceServerSocket($server, $bindContext, $this->chunkSize ?? ResourceSocket::DEFAULT_CHUNK_SIZE);
    }
}
