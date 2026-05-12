<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\Pipeline\Queue;
use Amp\Sync\Internal\ConcurrentIteratorChannel;
use Revolt\EventLoop\FiberLocal;

/**
 * Invokes the given Closure while maintaining a lock from the provided mutex.
 *
 * The lock is automatically released after the Closure returns.
 *
 * @template T
 *
 * @param \Closure(mixed...):T $synchronized
 *
 * @return T The return value of the Closure.
 */
function synchronized(Semaphore $semaphore, \Closure $synchronized, mixed ...$args): mixed
{
    static $reentry;
    $reentry ??= new FiberLocal(fn () => new \WeakMap());

    /** @var \WeakMap<Semaphore, bool> $existingLocks */
    $existingLocks = $reentry->get();
    if ($existingLocks[$semaphore] ?? false) {
        return $synchronized(...$args);
    }

    $lock = $semaphore->acquire();
    $existingLocks[$semaphore] = true;

    try {
        return $synchronized(...$args);
    } finally {
        unset($existingLocks[$semaphore]);
        $lock->release();
    }
}

/**
 * @param int $bufferSize Number of channel items to buffer in memory before back-pressure is applied.
 * @return array{Channel, Channel}
 */
function createChannelPair(int $bufferSize = 0): array
{
    $west = new Queue($bufferSize);
    $east = new Queue($bufferSize);

    return [
        new ConcurrentIteratorChannel($west->iterate(), $east),
        new ConcurrentIteratorChannel($east->iterate(), $west),
    ];
}
