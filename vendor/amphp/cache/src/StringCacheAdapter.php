<?php declare(strict_types=1);

namespace Amp\Cache;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class StringCacheAdapter implements StringCache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly Cache $cache)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);

        if ($value !== null && !\is_string($value)) {
            throw new CacheException(
                'Received unexpected type from ' . \get_class($this->cache) . ': ' . \get_debug_type($value)
            );
        }

        return $value;
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): ?bool
    {
        return $this->cache->delete($key);
    }
}
