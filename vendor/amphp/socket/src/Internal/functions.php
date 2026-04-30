<?php declare(strict_types=1);

namespace Amp\Socket\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\NullCancellation;
use Amp\Socket\TlsException;
use League\Uri\UriString;
use Revolt\EventLoop;

// Use Linux error codes if the socket extension is not available
\define(__NAMESPACE__ . '\CONNECTION_BUSY', \defined('SOCKET_EAGAIN') ? \SOCKET_EAGAIN : 11);
\define(__NAMESPACE__ . '\CONNECTION_TIMEOUT', \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110);
\define(__NAMESPACE__ . '\CONNECTION_REFUSED', \defined('SOCKET_ECONNREFUSED') ? \SOCKET_ECONNREFUSED : 111);

/**
 * Parse an URI into [scheme, host, port].
 *
 * @throws \Error If an invalid URI has been passed.
 *
 * @internal
 */
function parseUri(string $uri): array
{
    if (\stripos($uri, 'unix://') === 0) {
        /** @psalm-suppress PossiblyUndefinedArrayOffset */
        [$scheme, $path] = \explode('://', $uri, 2);
        return [$scheme, \ltrim($path, '/'), 0];
    }

    if (!\str_contains($uri, '://')) {
        // Set a default scheme of tcp if none was given.
        $uri = 'tcp://' . $uri;
    }

    try {
        $uriParts = UriString::parse($uri);
    } catch (\Exception $exception) {
        throw new \Error("Invalid URI: $uri", 0, $exception);
    }

    $scheme = $uriParts['scheme'];
    $host = $uriParts['host'] ?? '';
    $port = $uriParts['port'] ?? 0;

    if (!\in_array($scheme, ['tcp', 'udp', 'unix'], true)) {
        throw new \Error(
            "Invalid URI scheme ($scheme); tcp, udp, or unix scheme expected"
        );
    }

    if ($host === '') {
        throw new \Error(
            "Invalid URI: $uri; host component required"
        );
    }

    if (\str_contains($host, ':')) { // IPv6 address
        $host = \sprintf('[%s]', \trim($host, '[]'));
    }

    return [$scheme, $host, $port];
}

/**
 * Enable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @throws TlsException
 * @throws CancelledException
 *
 * @internal
 */
function setupTls($socket, array $options, ?Cancellation $cancellation): void
{
    $cancellation ??= new NullCancellation;

    if (isset(\stream_get_meta_data($socket)['crypto'])) {
        throw new TlsException("Can't setup TLS, because it has already been set up");
    }

    \error_clear_last();

    if (PHP_VERSION_ID >= 80300) {
        /** @psalm-suppress UndefinedFunction */
        \stream_context_set_options($socket, $options);
    } else {
        \stream_context_set_option($socket, $options);
    }

    $errorHandler = static function (int $errno, string $errstr) use ($socket): never {
        if (\feof($socket)) {
            $errstr = 'Connection reset by peer';
        }

        throw new TlsException('TLS negotiation failed: ' . $errstr);
    };

    try {
        \set_error_handler($errorHandler);
        $result = \stream_socket_enable_crypto($socket, enable: true);
        if ($result === false) {
            throw new TlsException('TLS negotiation failed: Unknown error');
        }
    } finally {
        \restore_error_handler();
    }

    // Yes, that function can return true / false / 0, don't use weak comparisons.
    if ($result === true) {
        /** @psalm-suppress InvalidReturnStatement */
        return;
    }

    while (true) {
        $cancellation->throwIfRequested();

        $suspension = EventLoop::getSuspension();

        // Watcher is guaranteed to be created, because we throw above if cancellation has already been requested
        /** @psalm-suppress PossiblyUndefinedVariable $callbackId is defined below. */
        $cancellationId = $cancellation->subscribe(static function ($e) use ($suspension, &$callbackId): void {
            EventLoop::cancel($callbackId);

            $suspension->throw($e);
        });

        $callbackId = EventLoop::onReadable($socket, static function () use (
            $suspension,
            $cancellation,
            $cancellationId,
        ): void {
            $cancellation->unsubscribe($cancellationId);

            $suspension->resume();
        });

        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($callbackId);
        }

        try {
            \set_error_handler($errorHandler);
            $result = \stream_socket_enable_crypto($socket, enable: true);
            if ($result === false) {
                $message = \feof($socket) ? 'Connection reset by peer' : 'Unknown error';
                throw new TlsException('TLS negotiation failed: ' . $message);
            }
        } finally {
            \restore_error_handler();
        }

        // If $result is 0, just wait for the next invocation
        if ($result === true) {
            break;
        }
    }
}

/**
 * Disable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @internal
 * @psalm-suppress InvalidReturnType
 */
function shutdownTls($socket): void
{
    \set_error_handler(static function (int $errno, string $errstr) use ($socket): never {
        if (\feof($socket)) {
            $errstr = 'Connection reset by peer';
        }

        throw new TlsException('TLS negotiation failed: ' . $errstr);
    });

    try {
        // note that disabling crypto *ALWAYS* returns false, immediately
        // don't set _enabled to false, TLS can be setup only once
        \stream_socket_enable_crypto($socket, enable: false);
    } finally {
        \restore_error_handler();
    }
}

/**
 * Normalizes "bindto" options to add a ":0" in case no port is present, otherwise PHP will silently ignore those.
 *
 * @throws \Error If an invalid option has been passed.
 *
 * @internal
 */
function normalizeBindToOption(?string $bindTo = null): ?string
{
    if ($bindTo === null) {
        return null;
    }

    if (\preg_match("/\\[(?P<ip>[0-9a-f:]+)](:(?P<port>\\d+))?$/", $bindTo, $match)) {
        $ip = $match['ip'];
        $port = (int) ($match['port'] ?? 0);

        if (\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv6 address: $ip");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: $port");
        }

        return "[$ip]:$port";
    }

    if (\preg_match("/(?P<ip>\\d+\\.\\d+\\.\\d+\\.\\d+)(:(?P<port>\\d+))?$/", $bindTo, $match)) {
        $ip = $match['ip'];
        $port = (int) ($match['port'] ?? 0);

        if (\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv4 address: $ip");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: $port");
        }

        return "$ip:$port";
    }

    throw new \Error("Invalid bindTo value: $bindTo");
}

/**
 * Alias of {@see stream_socket_get_name()} with errors suppressed.
 *
 * @param resource $resource
 *
 * @internal
 */
function getStreamSocketName($resource, bool $wantPeer): string|false
{
    static $errorHandler;

    \set_error_handler($errorHandler ??= static fn () => true);

    try {
        return \stream_socket_get_name($resource, $wantPeer);
    } finally {
        \restore_error_handler();
    }
}
