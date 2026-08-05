<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class LocalKeyedSemaphore implements KeyedSemaphore
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var LocalSemaphore[] */
    private array $semaphore = [];

    /** @var int[] */
    private array $locks = [];

    /**
     * @param positive-int $maxLocks
     */
    public function __construct(
        private readonly int $maxLocks,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($maxLocks < 1) {
            throw new \ValueError('The number of locks must be greater than 0, got ' . $maxLocks);
        }
    }

    public function acquire(string $key): Lock
    {
        if (!isset($this->semaphore[$key])) {
            $this->semaphore[$key] = new LocalSemaphore($this->maxLocks);
            $this->locks[$key] = 0;
        }

        $this->locks[$key]++;

        $lock = $this->semaphore[$key]->acquire();

        return new Lock(function () use ($lock, $key): void {
            if (--$this->locks[$key] === 0) {
                unset($this->semaphore[$key], $this->locks[$key]);
            }

            $lock->release();
        });
    }
}
