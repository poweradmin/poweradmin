<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;

/**
 * When a locked is released from this semaphore, it does not become available to be acquired again until
 * the given lock-period has elapsed. This is useful when a number of operations or requests must be
 * limited to a particular quantity within a certain time period.
 */
final class RateLimitingSemaphore implements Semaphore
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \SplQueue<string> List of event-loop delay callback IDs. */
    private readonly \SplQueue $timers;

    private int $waitingCount = 0;

    /**
     * @param float $lockPeriod Time after which a lock is released from the semaphore after being initially
     * released by the consumer.
     */
    public function __construct(
        private readonly Semaphore $semaphore,
        private readonly float $lockPeriod,
    ) {
        if ($lockPeriod <= 0) {
            throw new \ValueError('The lock period must be greater than 0, got ' . $lockPeriod);
        }

        $this->timers = new \SplQueue();
    }

    public function acquire(): Lock
    {
        ++$this->waitingCount;

        if (!$this->timers->isEmpty()) {
            EventLoop::reference($this->timers->bottom());
        }

        $lock = $this->semaphore->acquire();

        if (!--$this->waitingCount && !$this->timers->isEmpty()) {
            EventLoop::unreference($this->timers->bottom());
        }

        return new Lock(fn () => $this->release($lock));
    }

    private function release(Lock $lock): void
    {
        $timer = EventLoop::delay(
            $this->lockPeriod,
            function () use ($lock): void {
                \assert(!$this->timers->isEmpty());

                $this->timers->shift();
                if ($this->waitingCount && !$this->timers->isEmpty()) {
                    EventLoop::reference($this->timers->bottom());
                }

                $lock->release();
            },
        );

        if (!$this->waitingCount || !$this->timers->isEmpty()) {
            EventLoop::unreference($timer);
        }

        $this->timers->push($timer);
    }
}
