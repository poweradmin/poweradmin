<?php declare(strict_types=1);

namespace Amp\Cache;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;

/**
 * @template TValue
 */
final class AtomicCache
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param Cache<TValue> $cache
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly KeyedMutex $mutex,
    ) {
    }

    /**
     * Obtains the lock for the given key, then invokes the {@code $compute} callback with the current cached value
     * (which may be {@code null} if the key did not exist in the cache). The value returned from the callback is stored
     * in the cache and returned from this method.
     *
     * @param \Closure(string, TValue|null):TValue $compute Receives $key and $value as parameters.
     * @param int|null $ttl Timeout in seconds. The default {@code null} $ttl value indicates no timeout.
     *
     * @return TValue
     *
     * @throws CacheException If the $create callback throws an exception while generating the value.
     */
    public function compute(string $key, \Closure $compute, ?int $ttl = null): mixed
    {
        $lock = $this->lock($key);

        try {
            $value = $this->cache->get($key);

            return $this->create($compute, $key, $value, $ttl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Attempts to get the value for the given key. If the key is not found, the key is locked, the $compute callback
     * is invoked with the key as the first parameter. The value returned from the callback is stored in the cache and
     * returned from this method.
     *
     * @param string $key Cache key.
     * @param \Closure(string, null):TValue $compute Receives $key as parameter.
     * @param int|null $ttl Timeout in seconds. The default `null` $ttl value indicates no timeout.
     *
     * @return TValue
     *
     * @throws CacheException If the $compute callback throws an exception while generating the value.
     */
    public function computeIfAbsent(string $key, \Closure $compute, ?int $ttl = null): mixed
    {
        $value = $this->cache->get($key);

        if ($value !== null) {
            return $value;
        }

        $lock = $this->lock($key);

        try {
            // Attempt to get the value again, since it may have been set while obtaining the lock.
            return $this->cache->get($key) ?? $this->create($compute, $key, null, $ttl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Attempts to get the value for the given key. If the key exists, the key is locked, the $compute callback
     * is invoked with the key as the first parameter and the current key value as the second parameter. The value
     * returned from the callback is stored in the cache and returned from this method.
     *
     * @param string $key Cache key.
     * @param \Closure(string, TValue):TValue $compute Receives $key and $value as parameters.
     * @param int|null $ttl Timeout in seconds. The default {@code null} $ttl value indicates no timeout.
     *
     * @return TValue
     *
     * @throws CacheException If the $create callback throws an exception while generating the value.
     */
    public function computeIfPresent(string $key, \Closure $compute, ?int $ttl = null): mixed
    {
        $value = $this->cache->get($key);

        if ($value === null) {
            return null;
        }

        $lock = $this->lock($key);

        try {
            // Attempt to get the value again, since it may have been set while obtaining the lock.
            $value = $this->cache->get($key);

            if ($value === null) {
                return null;
            }

            return $this->create($compute, $key, $value, $ttl);
        } finally {
            $lock->release();
        }
    }

    /**
     * The lock is obtained for the key before setting the value.
     *
     * @param string $key Cache key.
     * @param TValue $value Value to cache.
     * @param int|null $ttl Timeout in seconds. The default `null` $ttl value indicates no timeout.
     *
     * @throws CacheException
     *
     * @see SerializedCache::set()
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $lock = $this->lock($key);

        try {
            $this->cache->set($key, $value, $ttl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns the cached value for the key or the given default value if the key does not exist.
     *
     * @template TDefault
     *
     * @param string $key Cache key.
     * @param TDefault $default Default value returned if the key does not exist. Null by default.
     *
     * @return TValue|TDefault Resolved with null iff $default is null.
     *
     * @throws CacheException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->cache->get($key);

        if ($value === null) {
            return $default;
        }

        return $value;
    }

    /**
     * The lock is obtained for the key before deleting the key.
     *
     * @throws CacheException
     */
    public function delete(string $key): ?bool
    {
        $lock = $this->lock($key);

        try {
            return $this->cache->delete($key);
        } finally {
            $lock->release();
        }
    }

    private function lock(string $key): Lock
    {
        try {
            return $this->mutex->acquire($key);
        } catch (\Throwable $exception) {
            throw new CacheException(
                \sprintf('Exception thrown when obtaining the lock for key "%s"', $key),
                0,
                $exception
            );
        }
    }

    /**
     * @param TValue|null $value
     *
     * @throws CacheException
     */
    private function create(\Closure $compute, string $key, mixed $value, ?int $ttl): mixed
    {
        try {
            $value = $compute($key, $value);
        } catch (\Throwable $exception) {
            throw new CacheException(
                \sprintf('Exception thrown while creating the value for key "%s"', $key),
                0,
                $exception
            );
        }

        $this->cache->set($key, $value, $ttl);

        return $value;
    }
}
