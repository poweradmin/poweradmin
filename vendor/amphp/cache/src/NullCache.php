<?php declare(strict_types=1);

namespace Amp\Cache;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Cache implementation that just ignores all operations and always resolves to `null`.
 *
 * @template TValue
 * @implements Cache<TValue>
 */
final class NullCache implements Cache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        // Nothing to do.
    }

    public function delete(string $key): bool
    {
        return false;
    }
}
