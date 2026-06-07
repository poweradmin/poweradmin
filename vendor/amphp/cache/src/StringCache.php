<?php declare(strict_types=1);

namespace Amp\Cache;

interface StringCache
{
    /**
     * Gets a value associated with the given key.
     *
     * If the specified key doesn't exist implementations MUST return {@code null}.
     *
     * @param $key string Cache key.
     *
     * @return string|null Returns the cached value, or {@code null} if it doesn't exist
     *
     * @throws CacheException On failure to determine the cached value
     */
    public function get(string $key): ?string;

    /**
     * Sets a value associated with the given key. Overrides existing values (if they exist).
     *
     * TTL values less than 0 MUST throw an \Error.
     *
     * @param $key string Cache key.
     * @param $value string Value to cache.
     * @param $ttl int Timeout in seconds >= 0. The default {@code null} $ttl value indicates no timeout.
     *
     * @throws CacheException On failure to store the cached value
     */
    public function set(string $key, string $value, ?int $ttl = null): void;

    /**
     * Deletes a value associated with the given key if it exists.
     *
     * Implementations SHOULD return boolean {@code true} or {@code false} to indicate whether the specified key
     * existed
     * at the time the delete operation was requested. If such information is not available, the implementation MUST
     * return {@code null}.
     *
     * Implementations MUST NOT error for non-existent keys.
     *
     * @param $key string Cache key.
     *
     * @return bool|null Returns {@code true} / {@code false} to indicate whether the key existed, or {@code null} if
     *     that information is not available.
     *
     * @throws CacheException On failure to delete the cached value
     */
    public function delete(string $key): ?bool;
}
