<?php declare(strict_types=1);

namespace Amp\Sync;

/**
 * A counting semaphore based on keys.
 *
 * Objects that implement this interface should guarantee that all operations are atomic. Implementations do not have to
 * guarantee that acquiring a lock is first-come, first serve.
 */
interface KeyedSemaphore
{
    /**
     * Acquires a lock on the semaphore.
     *
     * @param string $key Lock key
     *
     * @return Lock Returns an integer keyed lock object once a lock is obtained. Identifiers returned by the
     *    locks should be 0-indexed. Releasing an identifier MUST make that same identifier available. May fail with
     *    a SyncException if an error occurs when attempting to obtain the lock (e.g. a shared memory segment closed).
     */
    public function acquire(string $key): Lock;
}
