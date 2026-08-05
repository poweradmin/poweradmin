<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class StaticKeySemaphore implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly KeyedSemaphore $semaphore,
        private readonly string $key,
    ) {
    }

    public function acquire(): Lock
    {
        return $this->semaphore->acquire($this->key);
    }
}
