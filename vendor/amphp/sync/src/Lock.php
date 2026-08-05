<?php declare(strict_types=1);

namespace Amp\Sync;

use SplObjectStorage;
use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * A handle on an acquired lock from a synchronization object.
 *
 * This object is not thread-safe; after acquiring a lock from a mutex or semaphore, the lock should reside in the same
 * thread or process until it is released.
 */
final class Lock
{
    private static ?\Fiber $testFiber = null;

    private static ?\SplObjectStorage $pendingOperations = null;

    /** @var null|\Closure():void The function to be called on release or null if the lock has been released. */
    private ?\Closure $release;

    /**
     * Creates a new lock permit object.
     *
     * @param \Closure():void $release A function to be called upon release.
     */
    public function __construct(\Closure $release)
    {
        $this->release = $release;
    }

    private static function setupPendingOperations(): SplObjectStorage
    {
        $pending = new SplObjectStorage();

        \register_shutdown_function(static function () use ($pending): void {
            while ($pending->count() > 0) {
                awaitAll($pending);
            }
        });

        return $pending;
    }

    /**
     * Checks if the lock has already been released.
     *
     * @return bool True if the lock has already been released, otherwise false.
     */
    public function isReleased(): bool
    {
        return $this->release === null;
    }

    /**
     * Releases the lock. No-op if the lock has already been released.
     */
    public function release(): void
    {
        if ($this->release === null) {
            return;
        }

        // Invoke the releaser function given to us by the synchronization source to release the lock.
        $release = $this->release;
        $this->release = null;

        if ($this->isForceClosed()) {
            $future = async($release);

            $pending = self::$pendingOperations ??= self::setupPendingOperations();
            $pending->attach($future);
            $future->finally(fn () => $pending->detach($future));
        } else {
            $release();
        }
    }

    /**
     * Releases the lock when there are no more references to it.
     */
    public function __destruct()
    {
        if ($this->release) {
            async($this->release);
            $this->release = null;
        }
    }

    private function isForceClosed(): bool
    {
        $fiber = self::$testFiber ??= new \Fiber(function () {
            while (true) {
                \Fiber::suspend();
            }
        });

        try {
            if ($fiber->isStarted()) {
                $fiber->resume();
            } else {
                $fiber->start();
            }

            return false;
        } catch (\FiberError) {
            return true;
        }
    }
}
