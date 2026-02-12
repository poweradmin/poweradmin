<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\DeferredFuture;
use Amp\Future as AmpFuture;
use parallel\Events;
use parallel\Future as ParallelFuture;
use Revolt\EventLoop;

/** @internal */
final class ParallelHub
{
    private const EXIT_CHECK_FREQUENCY = 0.25;

    /** @var array<int, DeferredFuture> */
    private array $deferredFutures = [];

    private readonly string $watcher;

    private readonly Events $events;

    public function __construct()
    {
        $events = $this->events = new Events();
        $this->events->setBlocking(false);

        $deferredFutures = &$this->deferredFutures;
        $this->watcher = EventLoop::repeat(self::EXIT_CHECK_FREQUENCY, static function () use (
            &$deferredFutures,
            $events,
        ): void {
            while ($event = $events->poll()) {
                $id = (int) $event->source;
                \assert(isset($deferredFutures[$id]), 'Deferred future for context ID not found');
                $deferredFuture = $deferredFutures[$id];
                unset($deferredFutures[$id]);
                $deferredFuture->complete();
            }
        });
        EventLoop::disable($this->watcher);
        EventLoop::unreference($this->watcher);
    }

    public function add(int $id, ParallelFuture $future): AmpFuture
    {
        $this->deferredFutures[$id] = $deferred = new DeferredFuture();
        $this->events->addFuture((string) $id, $future);

        EventLoop::enable($this->watcher);

        return $deferred->getFuture();
    }

    public function remove(int $id): void
    {
        $deferred = $this->deferredFutures[$id] ?? null;
        if (!$deferred) {
            return;
        }

        if (!$deferred->isComplete()) {
            $deferred->complete();
        }

        unset($this->deferredFutures[$id]);
        $this->events->remove((string) $id);

        if (empty($this->deferredFutures)) {
            EventLoop::disable($this->watcher);
        }
    }
}
