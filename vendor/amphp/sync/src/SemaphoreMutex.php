<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class SemaphoreMutex implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    private bool $locked = false;

    /**
     * @param Semaphore $semaphore A semaphore with a single lock.
     */
    public function __construct(
        private readonly Semaphore $semaphore
    ) {
    }

    /** {@inheritdoc} */
    public function acquire(): Lock
    {
        $lock = $this->semaphore->acquire();

        if ($this->locked) {
            throw new \Error("Cannot use a semaphore with more than a single lock");
        }

        $this->locked = true;

        return new Lock(function () use ($lock): void {
            $this->locked = false;
            $lock->release();
        });
    }
}
