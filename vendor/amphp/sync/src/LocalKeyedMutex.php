<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class LocalKeyedMutex implements KeyedMutex
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalKeyedSemaphore $semaphore;

    public function __construct()
    {
        $this->semaphore = new LocalKeyedSemaphore(1);
    }

    public function acquire(string $key): Lock
    {
        return $this->semaphore->acquire($key);
    }
}
