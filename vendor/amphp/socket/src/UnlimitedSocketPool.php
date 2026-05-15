<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use League\Uri\UriString;
use Revolt\EventLoop;

/**
 * SocketPool implementation that doesn't impose any limits on concurrent open connections.
 *
 * @psalm-type SocketEntry = object{
 *     uri: string,
 *     object: Socket,
 *     isAvailable: bool,
 *     idleWatcher: string|null,
 * }
 */
final class UnlimitedSocketPool implements SocketPool
{
    use ForbidCloning;
    use ForbidSerialization;

    private const ALLOWED_SCHEMES = [
        'tcp' => null,
        'unix' => null,
    ];

    /** @var array<string, array<int, SocketEntry>> */
    private array $sockets = [];

    /** @var array<int, string> */
    private array $objectIdCacheKeyMap = [];

    /** @var int[] */
    private array $pendingCount = [];

    public function __construct(
        private readonly float $idleTimeout = 10,
        private readonly ?SocketConnector $connector = null,
    ) {
    }

    public function checkout(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null,
    ): Socket {
        // A request might already be cancelled before we reach the checkout, so do not even attempt to checkout in that
        // case. The weird logic is required to throw the token's exception instead of creating a new one.
        if ($cancellation && $cancellation->isRequested()) {
            $cancellation->throwIfRequested();
        }

        [$uri, $fragment] = $this->normalizeUri($uri);

        $cacheKey = $uri;

        if ($context && ($tlsContext = $context->getTlsContext())) {
            $cacheKey .= ' + ' . \serialize($tlsContext->toStreamContextArray());
        }

        if ($fragment !== null) {
            $cacheKey .= ' # ' . $fragment;
        }

        if (empty($this->sockets[$cacheKey])) {
            return $this->checkoutNewSocket($uri, $cacheKey, $context, $cancellation);
        }

        foreach ($this->sockets[$cacheKey] as $socket) {
            if (!$socket->isAvailable) {
                continue;
            }

            if ($socket->object instanceof ResourceSocket) {
                $resource = $socket->object->getResource();

                if (!$resource || !\is_resource($resource) || \feof($resource)) {
                    $this->clearFromId($socket->object);
                    continue;
                }
            } elseif ($socket->object->isClosed()) {
                $this->clearFromId($socket->object);
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                EventLoop::disable($socket->idleWatcher);
            }

            return $socket->object;
        }

        return $this->checkoutNewSocket($uri, $cacheKey, $context, $cancellation);
    }

    public function clear(Socket $socket): void
    {
        $this->clearFromId($socket);
    }

    public function checkin(Socket $socket): void
    {
        $objectId = \spl_object_id($socket);

        if (!isset($this->objectIdCacheKeyMap[$objectId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $objectId)
            );
        }

        $cacheKey = $this->objectIdCacheKeyMap[$objectId];

        if ($socket instanceof ResourceSocket) {
            $resource = $socket->getResource();

            if (!$resource || !\is_resource($resource) || \feof($resource)) {
                $this->clearFromId($socket);
                return;
            }
        } elseif ($socket->isClosed()) {
            $this->clearFromId($socket);
            return;
        }

        $socket = $this->sockets[$cacheKey][$objectId];
        $socket->isAvailable = true;

        $socket->idleWatcher ??= EventLoop::unreference(EventLoop::delay(
            $this->idleTimeout,
            fn () => $this->clearFromId($socket->object),
        ));

        EventLoop::enable($socket->idleWatcher);
    }

    /**
     * @throws SocketException
     */
    private function normalizeUri(string $uri): array
    {
        if (\stripos($uri, 'unix://') === 0) {
            return \explode('#', $uri) + [null, null];
        }

        try {
            $parts = UriString::parse($uri);
        } catch (\Exception $exception) {
            throw new SocketException('Could not parse URI', 0, $exception);
        }

        if ($parts['scheme'] === null) {
            throw new SocketException('Invalid URI for socket pool; no scheme given');
        }

        $port = $parts['port'] ?? 0;

        if ($port === 0 || $parts['host'] === null) {
            throw new SocketException('Invalid URI for socket pool; missing host or port');
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);

        if (!\array_key_exists($scheme, self::ALLOWED_SCHEMES)) {
            throw new SocketException(\sprintf(
                "Invalid URI for socket pool; '%s' scheme not allowed - scheme must be one of %s",
                $scheme,
                \implode(', ', \array_keys(self::ALLOWED_SCHEMES))
            ));
        }

        if ($parts['query'] !== null) {
            throw new SocketException('Invalid URI for socket pool; query component not allowed');
        }

        if ($parts['path'] !== '') {
            throw new SocketException('Invalid URI for socket pool; path component must be empty');
        }

        if ($parts['user'] !== null) {
            throw new SocketException('Invalid URI for socket pool; user component not allowed');
        }

        return [$scheme . '://' . $host . ':' . $port, $parts['fragment']];
    }

    private function checkoutNewSocket(
        string $uri,
        string $cacheKey,
        ?ConnectContext $connectContext = null,
        ?Cancellation $cancellation = null,
    ): Socket {
        $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

        try {
            $socket = ($this->connector ?? socketConnector())->connect($uri, $connectContext, $cancellation);
        } finally {
            if (--$this->pendingCount[$uri] === 0) {
                unset($this->pendingCount[$uri]);
            }
        }

        /** @psalm-suppress MissingConstructor */
        $socketEntry = new class($uri, $socket) {
            public bool $isAvailable = false;
            public ?string $idleWatcher = null;

            public function __construct(
                public readonly string $uri,
                public readonly Socket $object,
            ) {
            }
        };

        $objectId = \spl_object_id($socket);
        $this->sockets[$cacheKey][$objectId] = $socketEntry;
        $this->objectIdCacheKeyMap[$objectId] = $cacheKey;

        return $socket;
    }

    private function clearFromId(Socket $socket): void
    {
        $objectId = \spl_object_id($socket);

        if (!isset($this->objectIdCacheKeyMap[$objectId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $objectId)
            );
        }

        $cacheKey = $this->objectIdCacheKeyMap[$objectId];
        $socket = $this->sockets[$cacheKey][$objectId];

        if ($socket->idleWatcher) {
            EventLoop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$cacheKey][$objectId],
            $this->objectIdCacheKeyMap[$objectId],
        );

        if (empty($this->sockets[$cacheKey])) {
            unset($this->sockets[$cacheKey]);
        }
    }
}
