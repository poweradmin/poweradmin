<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class PrefixedKeyedSemaphore implements KeyedSemaphore
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly KeyedSemaphore $semaphore,
        private readonly string $prefix
    ) {
    }

    public function acquire(string $key): Lock
    {
        return $this->semaphore->acquire($this->prefix . $key);
    }
}
