<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use League\Uri\Uri;

/** @api */
final class Socks5SocketConnector implements SocketConnector
{
    private const REPLIES = [
        0 => 'succeeded',
        1 => 'general SOCKS server failure',
        2 => 'connection not allowed by ruleset',
        3 => 'Network unreachable',
        4 => 'Host unreachable',
        5 => 'Connection refused',
        6 => 'TTL expired',
        7 => 'Command not supported',
        8 => 'Address type not supported'
    ];

    /**
     * @throws StreamException
     * @see https://datatracker.ietf.org/doc/html/rfc1928#section-3
     */
    private static function writeHello(?string $username, ?string $password, Socket $socket): void
    {
        $methods = \chr(0);
        if (isset($username) && isset($password)) {
            $methods .= \chr(2);
        }

        $socket->write(\chr(5) . \chr(\strlen($methods)) . $methods);
    }

    /**
     * @throws SocketException
     * @throws StreamException
     * @see https://datatracker.ietf.org/doc/html/rfc1928#section-4
     */
    private static function writeConnectRequest(Uri $uri, Socket $socket): void
    {
        $host = $uri->getHost();
        if ($host === null) {
            throw new SocketException("Host is null!");
        }

        $payload = \pack('C3', 0x5, 0x1, 0x0);

        $ip = \inet_pton($host);
        if ($ip !== false) {
            $payload .= \chr(\strlen($ip) === 4 ? 0x1 : 0x4) . $ip;
        } else {
            $payload .= \chr(0x3) . \chr(\strlen($host)) . $host;
        }

        $payload .= \pack('n', $uri->getPort());

        $socket->write($payload);
    }

    use ForbidCloning;
    use ForbidSerialization;

    public static function tunnel(
        Socket $socket,
        string $target,
        ?string $username,
        ?string $password,
        ?Cancellation $cancellation
    ): void {
        if (($username === null) !== ($password === null)) {
            throw new \Error("Both or neither username and password must be provided!");
        }

        /** @psalm-suppress DeprecatedMethod */
        $uri = Uri::createFromString($target);

        $read = function (int $length) use ($socket, $cancellation): string {
            \assert($length > 0);

            $buffer = '';

            do {
                $limit = $length - \strlen($buffer);
                \assert($limit > 0);

                $chunk = $socket->read($cancellation, $limit);
                if ($chunk === null) {
                    throw new SocketException("The socket was closed before the tunnel could be established");
                }

                $buffer .= $chunk;
            } while (\strlen($buffer) !== $length);

            return $buffer;
        };

        self::writeHello($username, $password, $socket);

        $version = \ord($read(1));
        if ($version !== 5) {
            throw new SocketException("Wrong SOCKS5 version: $version");
        }

        $method = \ord($read(1));
        if ($method === 2) {
            if ($username === null || $password === null) {
                throw new SocketException("Unexpected method: $method");
            }

            $socket->write(
                \chr(1) .
                \chr(\strlen($username)) .
                $username .
                \chr(\strlen($password)) .
                $password
            );

            $version = \ord($read(1));
            if ($version !== 1) {
                throw new SocketException("Wrong authorized SOCKS version: $version");
            }

            $result = \ord($read(1));
            if ($result !== 0) {
                throw new SocketException("Wrong authorization status: $result");
            }
        } elseif ($method !== 0) {
            throw new SocketException("Unexpected method: $method");
        }

        self::writeConnectRequest($uri, $socket);

        $version = \ord($read(1));
        if ($version !== 5) {
            throw new SocketException("Wrong SOCKS5 version: $version");
        }

        $reply = \ord($read(1));
        if ($reply !== 0) {
            $reply = self::REPLIES[$reply] ?? $reply;
            throw new SocketException("Wrong SOCKS5 reply: $reply");
        }

        $rsv = \ord($read(1));
        if ($rsv !== 0) {
            throw new SocketException("Wrong SOCKS5 RSV: $rsv");
        }

        $read(match (\ord($read(1))) {
            0x1 => 6,
            0x4 => 18,
            0x3 => \ord($read(1)) + 2
        });
    }

    public function __construct(
        private readonly SocketAddress|string $proxyAddress,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?SocketConnector $socketConnector = null
    ) {
        if (($username === null) !== ($password === null)) {
            throw new \Error("Both or neither username and password must be provided!");
        }
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $connector = $this->socketConnector ?? socketConnector();

        $socket = $connector->connect($this->proxyAddress, $context, $cancellation);
        self::tunnel($socket, (string) $uri, $this->username, $this->password, $cancellation);

        return $socket;
    }
}
