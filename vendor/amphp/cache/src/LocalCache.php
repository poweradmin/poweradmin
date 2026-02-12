<?php declare(strict_types=1);

namespace Amp\Cache;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;

/**
 * A cache which stores data in an in-memory (local) array.
 * This class may be used as a least-recently-used (LRU) cache of a given size.
 * Iterating over the cache will iterate from least-recently-used to most-recently-used.
 *
 * @template TValue
 * @implements Cache<TValue>
 * @implements \IteratorAggregate<string, TValue>
 */
final class LocalCache implements Cache, \Countable, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly object $state;

    private readonly string $gcCallbackId;

    /** @var int<1, max>|null */
    private readonly ?int $sizeLimit;

    /**
     * @param int<1, max>|null $sizeLimit The maximum size of cache array (number of elements). NULL for unlimited size.
     * @param float $gcInterval The frequency in seconds at which expired cache entries should be garbage collected.
     */
    public function __construct(?int $sizeLimit = null, float $gcInterval = 5)
    {
        if ($sizeLimit !== null && $sizeLimit < 1) {
            throw new \Error('Invalid sizeLimit, must be > 0: ' . $sizeLimit);
        }

        // By using a separate state object we're able to use `__destruct()` for garbage collection of both this
        // instance and the event loop callback. Otherwise, this object could only be collected when the garbage
        // collection callback was cancelled at the event loop layer.
        $this->state = $state = new class {
            public array $cache = [];

            /** @var array<string, int> */
            public array $cacheTimeouts = [];

            public bool $isSortNeeded = false;

            public function collectGarbage(): void
            {
                $now = \time();

                if ($this->isSortNeeded) {
                    \asort($this->cacheTimeouts);
                    $this->isSortNeeded = false;
                }

                foreach ($this->cacheTimeouts as $key => $expiry) {
                    if ($now <= $expiry) {
                        break;
                    }

                    unset(
                        $this->cache[$key],
                        $this->cacheTimeouts[$key]
                    );
                }
            }
        };

        $this->gcCallbackId = EventLoop::repeat($gcInterval, $state->collectGarbage(...));
        $this->sizeLimit = $sizeLimit;

        EventLoop::unreference($this->gcCallbackId);
    }

    public function __destruct()
    {
        $this->state->cache = [];
        $this->state->cacheTimeouts = [];

        EventLoop::cancel($this->gcCallbackId);
    }

    public function get(string $key): mixed
    {
        if (!isset($this->state->cache[$key])) {
            return null;
        }

        $value = $this->state->cache[$key];
        unset($this->state->cache[$key]);

        if (isset($this->state->cacheTimeouts[$key]) && \time() > $this->state->cacheTimeouts[$key]) {
            unset($this->state->cacheTimeouts[$key]);

            return null;
        }

        $this->state->cache[$key] = $value;

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($value === null) {
            throw new CacheException('Cannot store NULL in ' . self::class);
        }

        if ($ttl === null) {
            unset($this->state->cacheTimeouts[$key]);
        } elseif ($ttl >= 0) {
            $expiry = \time() + $ttl;
            $this->state->cacheTimeouts[$key] = $expiry;
            $this->state->isSortNeeded = true;
        } else {
            throw new \Error("Invalid cache TTL ({$ttl}; integer >= 0 or null required");
        }

        unset($this->state->cache[$key]);
        if (\count($this->state->cache) === $this->sizeLimit) {
            /** @var array-key $keyToEvict */
            $keyToEvict = \array_key_first($this->state->cache);
            unset($this->state->cache[$keyToEvict]);
        }

        $this->state->cache[$key] = $value;
    }

    public function delete(string $key): bool
    {
        $exists = isset($this->state->cache[$key]);

        unset(
            $this->state->cache[$key],
            $this->state->cacheTimeouts[$key]
        );

        return $exists;
    }

    public function count(): int
    {
        return \count($this->state->cache);
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->state->cache as $key => $value) {
            yield (string) $key => $value;
        }
    }
}
