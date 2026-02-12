<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class PrefixedKeyedMutex implements KeyedMutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly string $prefix
    ) {
    }

    public function acquire(string $key): Lock
    {
        return $this->mutex->acquire($this->prefix . $key);
    }
}
