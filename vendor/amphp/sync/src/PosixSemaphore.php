<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use function Amp\delay;

/**
 * A non-blocking, inter-process POSIX semaphore.
 *
 * Uses a POSIX message queue to store a queue of permits in a lock-free data structure. This semaphore implementation
 * is preferred over other implementations when available, as it provides the best performance.
 *
 * Not compatible with Windows.
 */
final class PosixSemaphore implements Semaphore
{
    use ForbidCloning;
    use ForbidSerialization;

    private const LATENCY_TIMEOUT = 0.01;
    private const MAX_ID = 0x7fffffff;

    private static int $nextId = 0;

    private readonly \Closure $errorHandler;

    /**
     * Creates a new semaphore with a given ID and number of locks.
     *
     * @param int $maxLocks The maximum number of locks that can be acquired from the semaphore.
     * @param int $permissions Permissions to access the semaphore. Use file permission format specified as 0xxx.
     *
     * @throws SyncException If the semaphore could not be created due to an internal error.
     */
    public static function create(int $maxLocks, int $permissions = 0600): self
    {
        if ($maxLocks < 1) {
            throw new \Error('Number of locks must be greater than 0, got ' . $maxLocks);
        }

        \set_error_handler(static function (int $errno, string $errstr): bool {
            if (!\str_contains($errstr, 'No space left on device') && \str_contains($errstr, 'Failed for key')) {
                return true;
            }

            throw new SyncException('Failed to create semaphore: ' . $errstr, $errno);
        });

        try {
            do {
                do {
                    $id = self::getNextId();
                } while (\msg_queue_exists($id));

                if ($queue = \msg_get_queue($id, $permissions)) {
                    $semaphore = new self($queue, $id, \getmypid());

                    // Fill the semaphore with locks.
                    while (--$maxLocks >= 0) {
                        $semaphore->release();
                    }

                    return $semaphore;
                }
            } while (true);
        } finally {
            \restore_error_handler();
        }
    }

    private static function getNextId(): int
    {
        if (self::$nextId === 0) {
            return self::$nextId = \random_int(1, self::MAX_ID);
        }

        return self::$nextId = self::$nextId % self::MAX_ID + 1;
    }

    /**
     * @param int $key Use {@see getKey()} on the creating process and send this key to another process.
     */
    public static function use(int $key): self
    {
        if (!\msg_queue_exists($key)) {
            throw new SyncException('No semaphore with that ID found');
        }

        $queue = \msg_get_queue($key);

        if (!$queue) {
            throw new SyncException('Failed to open the semaphore.');
        }

        return new self($queue, $key, 0);
    }

    /**
     * @param int $initializer PID of the process that created the semaphore or 0 if the semaphore was only opened
     *      in this process.
     *
     * @throws \Error If the sysvmsg extension is not loaded.
     */
    private function __construct(
        private readonly \SysvMessageQueue $queue,
        private readonly int $key,
        private readonly int $initializer,
    ) {
        if (!\extension_loaded("sysvmsg")) {
            throw new \Error(__CLASS__ . " requires the sysvmsg extension.");
        }

        $this->errorHandler = static fn () => true;
    }

    public function getKey(): int
    {
        return $this->key;
    }

    /**
     * Gets the access permissions of the semaphore.
     *
     * @return int A permissions mode.
     */
    public function getPermissions(): int
    {
        $stat = \msg_stat_queue($this->queue);
        return $stat['msg_perm.mode'];
    }

    /**
     * Sets the access permissions of the semaphore.
     *
     * The current user must have access to the semaphore in order to change the permissions.
     *
     * @param int $mode A permissions mode to set.
     *
     * @throws SyncException If the operation failed.
     */
    public function setPermissions(int $mode): void
    {
        if (!\msg_set_queue($this->queue, ['msg_perm.mode' => $mode])) {
            throw new SyncException('Failed to change the semaphore permissions.');
        }
    }

    public function acquire(): Lock
    {
        do {
            // Attempt to acquire a lock from the semaphore.
            \set_error_handler($this->errorHandler);

            try {
                if (\msg_receive($this->queue, 0, $type, 1, $message, false, \MSG_IPC_NOWAIT, $errno)) {
                    // A free lock was found, so resolve with a lock object that can
                    // be used to release the lock.
                    return new Lock($this->release(...));
                }
            } finally {
                \restore_error_handler();
            }

            // Check for unusual errors.
            if ($errno !== \MSG_ENOMSG) {
                throw new SyncException(\sprintf('Failed to acquire a lock; errno: %d', $errno));
            }

            delay(self::LATENCY_TIMEOUT);
        } while (true);
    }

    /**
     * Removes the semaphore if it still exists.
     *
     * @throws SyncException If the operation failed.
     */
    public function __destruct()
    {
        if ($this->initializer === 0 || $this->initializer !== \getmypid()) {
            return;
        }

        if (!\msg_queue_exists($this->key)) {
            return;
        }

        \msg_remove_queue($this->queue);
    }

    /**
     * Releases a lock from the semaphore.
     *
     * @throws SyncException If the operation failed.
     */
    private function release(): void
    {
        // Send in non-blocking mode. If the call fails because the queue is full,
        // then the number of locks configured is too large.
        \set_error_handler($this->errorHandler);

        try {
            if (!\msg_send($this->queue, 1, "\0", false, false, $errno)) {
                if ($errno === \MSG_EAGAIN) {
                    throw new SyncException('The semaphore size is larger than the system allows.');
                }

                throw new SyncException('Failed to release the lock.');
            }
        } finally {
            \restore_error_handler();
        }
    }
}
