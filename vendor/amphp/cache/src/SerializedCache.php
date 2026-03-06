<?php declare(strict_types=1);

namespace Amp\Cache;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;

/**
 * @template TValue
 * @implements Cache<TValue>
 */
final class SerializedCache implements Cache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly StringCache $cache,
        private readonly Serializer $serializer,
    ) {
    }

    /**
     * Fetch a value from the cache and unserialize it.
     *
     * @param $key string Cache key.
     *
     * @return TValue Returns the cached value or {@code null} if it doesn't exist.
     *
     * @throws CacheException
     * @throws SerializationException
     *
     * @see StringCache::get()
     */
    public function get(string $key): mixed
    {
        $data = $this->cache->get($key);
        if ($data === null) {
            return null;
        }

        return $this->serializer->unserialize($data);
    }

    /**
     * Serializes a value and stores its serialization to the cache.
     *
     * @param string $key Cache key.
     * @param TValue $value Value to cache.
     * @param int|null $ttl Timeout in seconds. The default {@code null} $ttl value indicates no timeout. Values less
     *     than 0 MUST throw an \Error.
     *
     * @throws CacheException
     * @throws SerializationException
     *
     * @see StringCache::set()
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($value === null) {
            throw new CacheException('Cannot store NULL in ' . self::class);
        }

        $value = $this->serializer->serialize($value);

        $this->cache->set($key, $value, $ttl);
    }

    /**
     * Deletes a value associated with the given key if it exists.
     *
     * @param $key string Cache key.
     *
     * @return bool|null Returns {@code true} / {@code false} to indicate whether the key existed, or {@code null} if
     *     that information is not available.
     *
     * @throws CacheException
     *
     * @see StringCache::delete()
     */
    public function delete(string $key): ?bool
    {
        return $this->cache->delete($key);
    }
}
