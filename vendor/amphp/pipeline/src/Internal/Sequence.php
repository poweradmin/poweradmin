<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/** @internal */
final class Sequence
{
    /** @var non-negative-int */
    private int $position = 0;

    /** @var array<int, Suspension> */
    private array $suspensions = [];

    public function await(int $position): void
    {
        if ($position <= $this->position) {
            return;
        }

        \assert(!isset($this->suspensions[$position]));

        $suspension = EventLoop::getSuspension();
        $this->suspensions[$position] = $suspension;
        $suspension->suspend();
    }

    public function resume(int $position): void
    {
        if ($position < $this->position) {
            return;
        }

        $newPosition = \max($position, $this->position) + 1;

        if ($newPosition === \PHP_INT_MAX) {
            foreach ($this->suspensions as $suspension) {
                $suspension->resume();
            }

            $this->suspensions = [];
        } else {
            for ($i = $this->position + 1; $i <= $newPosition; $i++) {
                if (isset($this->suspensions[$i])) {
                    $this->suspensions[$i]->resume();
                    unset($this->suspensions[$i]);
                }
            }
        }

        $this->position = $newPosition;
    }

    /**
     * Awaiting and immediately resuming acts as a barrier, ensuring callbacks are executed in order.
     * Note that resuming a suspension is async, so the code immediately following this call is executed
     * before resuming other coroutines.
     */
    public function barrier(int $position): void
    {
        $this->await($position);
        $this->resume($position);
    }

    public function dispose(): void
    {
        $this->resume(\PHP_INT_MAX - 1);
    }
}
