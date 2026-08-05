<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class StaticKeyMutex implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly string $key,
    ) {
    }

    public function acquire(): Lock
    {
        return $this->mutex->acquire($this->key);
    }
}
