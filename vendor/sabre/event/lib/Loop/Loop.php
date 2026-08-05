<?php

declare(strict_types=1);

namespace Sabre\Event\Loop;

/**
 * A simple eventloop implementation.
 *
 * This eventloop supports:
 *   * nextTick
 *   * setTimeout for delayed functions
 *   * setInterval for repeating functions
 *   * stream events using stream_select
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Loop
{
    /**
     * Executes a function after x seconds.
     */
    public function setTimeout(callable $cb, float $timeout): void
    {
        $triggerTime = microtime(true) + $timeout;

        if (0 === count($this->timers)) {
            // Special case when the timers array was empty.
            $this->timers[] = [$triggerTime, $cb];

            return;
        }

        // We need to insert these values in the timers array, but the timers
        // array must be in reverse-order of trigger times.
        //
        // So here we search the array for the insertion point.
        $index = count($this->timers) - 1;
        while (true) {
            if ($triggerTime < $this->timers[$index][0]) {
                array_splice(
                    $this->timers,
                    $index + 1,
                    0,
                    [[$triggerTime, $cb]]
                );
                break;
            } elseif (0 === $index) {
                array_unshift($this->timers, [$triggerTime, $cb]);
                break;
            }
            --$index;
        }
    }

    /**
     * Executes a function every x seconds.
     *
     * The value this function returns can be used to stop the interval with
     * clearInterval.
     *
     * @return array{0:string, 1:bool}
     */
    public function setInterval(callable $cb, float $timeout): array
    {
        $keepGoing = true;
        $f = null;

        $f = function () use ($cb, &$f, $timeout, &$keepGoing): void {
            if ($keepGoing) {
                $cb();
                $this->setTimeout($f, $timeout);
            }
        };
        $this->setTimeout($f, $timeout);

        // Really the only thing that matters is returning the $keepGoing
        // boolean value.
        //
        // We need to pack it in an array to allow returning by reference.
        // Because I'm worried people will be confused by using a boolean as a
        // sort of identifier, I added an extra string.
        return ['I\'m an implementation detail', &$keepGoing];
    }

    /**
     * Stops a running interval.
     *
     * @param array{0:string, 1:bool} $intervalId
     */
    public function clearInterval(array $intervalId): void
    {
        $intervalId[1] = false;
    }

    /**
     * Runs a function immediately at the next iteration of the loop.
     */
    public function nextTick(callable $cb): void
    {
        $this->nextTick[] = $cb;
    }

    /**
     * Adds a read stream.
     *
     * The callback will be called as soon as there is something to read from
     * the stream.
     *
     * You MUST call removeReadStream after you are done with the stream, to
     * prevent the eventloop from never stopping.
     *
     * @param resource $stream
     */
    public function addReadStream($stream, callable $cb): void
    {
        $this->readStreams[(int) $stream] = $stream;
        $this->readCallbacks[(int) $stream] = $cb;
    }

    /**
     * Adds a write stream.
     *
     * The callback will be called as soon as the system reports it's ready to
     * receive writes on the stream.
     *
     * You MUST call removeWriteStream after you are done with the stream, to
     * prevent the eventloop from never stopping.
     *
     * @param resource $stream
     */
    public function addWriteStream($stream, callable $cb): void
    {
        $this->writeStreams[(int) $stream] = $stream;
        $this->writeCallbacks[(int) $stream] = $cb;
    }

    /**
     * Stop watching a stream for reads.
     *
     * @param resource $stream
     */
    public function removeReadStream($stream): void
    {
        unset(
            $this->readStreams[(int) $stream],
            $this->readCallbacks[(int) $stream]
        );
    }

    /**
     * Stop watching a stream for writes.
     *
     * @param resource $stream
     */
    public function removeWriteStream($stream): void
    {
        unset(
            $this->writeStreams[(int) $stream],
            $this->writeCallbacks[(int) $stream]
        );
    }

    /**
     * Runs the loop.
     *
     * This function will run continuously, until there's no more events to
     * handle.
     */
    public function run(): void
    {
        $this->running = true;

        do {
            $hasEvents = $this->tick(true);
        } while ($this->running && $hasEvents);
        $this->running = false;
    }

    /**
     * Executes all pending events.
     *
     * If $block is turned true, this function will block until any event is
     * triggered.
     *
     * If there are now timeouts, nextTick callbacks or events in the loop at
     * all, this function will exit immediately.
     *
     * This function will return true if there are _any_ events left in the
     * loop after the tick.
     */
    public function tick(bool $block = false): bool
    {
        $this->runNextTicks();
        $nextTimeout = $this->runTimers();

        // Calculating how long runStreams should at most wait.
        if (!$block) {
            // Don't wait
            $streamWait = 0;
        } elseif (count($this->nextTick) > 0) {
            // There's a pending 'nextTick'. Don't wait.
            $streamWait = 0;
        } elseif (is_numeric($nextTimeout)) {
            // Wait until the next Timeout should trigger.
            $streamWait = $nextTimeout;
        } else {
            // Wait indefinitely
            $streamWait = null;
        }

        $this->runStreams($streamWait);

        return count($this->readStreams) > 0 || count($this->writeStreams) > 0 || count($this->nextTick) > 0 || count($this->timers) > 0;
    }

    /**
     * Stops a running eventloop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Executes all 'nextTick' callbacks.
     *
     * return void
     */
    protected function runNextTicks(): void
    {
        $nextTick = $this->nextTick;
        $this->nextTick = [];

        foreach ($nextTick as $cb) {
            $cb();
        }
    }

    /**
     * Runs all pending timers.
     *
     * After running the timer callbacks, this function returns the number of
     * seconds until the next timer should be executed.
     *
     * If there's no more pending timers, this function returns null.
     */
    protected function runTimers(): ?float
    {
        $now = microtime(true);
        while (($timer = array_pop($this->timers)) !== null && $timer[0] < $now) {
            $timer[1]();
        }
        // Add the last timer back to the array.
        if (null !== $timer) {
            $this->timers[] = $timer;

            return max(0, $timer[0] - microtime(true));
        }

        return null;
    }

    /**
     * Runs all pending stream events.
     *
     * If $timeout is 0, it will return immediately. If $timeout is null, it
     * will wait indefinitely.
     */
    protected function runStreams(?float $timeout): void
    {
        if (count($this->readStreams) > 0 || count($this->writeStreams) > 0) {
            $read = $this->readStreams;
            $write = $this->writeStreams;
            $except = null;
            // stream_select changes behavior in 8.1 to forbid passing non-null microseconds when the seconds are null.
            // Older versions of php don't allow passing null to microseconds.
            if (null !== $timeout ? stream_select($read, $write, $except, 0, (int) ($timeout * 1000000)) : stream_select($read, $write, $except, null)) {
                // See PHP Bug https://bugs.php.net/bug.php?id=62452
                // Fixed in PHP7
                foreach ($read as $readStream) {
                    $readCb = $this->readCallbacks[(int) $readStream];
                    $readCb();
                }
                foreach ($write as $writeStream) {
                    $writeCb = $this->writeCallbacks[(int) $writeStream];
                    $writeCb();
                }
            }
        } elseif ($this->running && (count($this->nextTick) > 0 || count($this->timers) > 0)) {
            usleep(null !== $timeout ? intval($timeout * 1000000) : 200000);
        }
    }

    /**
     * Is the main loop active.
     */
    protected bool $running = false;

    /**
     * A list of timers, added by setTimeout.
     *
     * @var list<array{0:float, 1:callable}>
     */
    protected array $timers = [];

    /**
     * A list of 'nextTick' callbacks.
     *
     * @var callable[]
     */
    protected array $nextTick = [];

    /**
     * List of readable streams for stream_select, indexed by stream id.
     *
     * @var resource[]
     */
    protected array $readStreams = [];

    /**
     * List of writable streams for stream_select, indexed by stream id.
     *
     * @var resource[]
     */
    protected array $writeStreams = [];

    /**
     * List of read callbacks, indexed by stream id.
     *
     * @var callable[]
     */
    protected array $readCallbacks = [];

    /**
     * List of write callbacks, indexed by stream id.
     *
     * @var callable[]
     */
    protected array $writeCallbacks = [];
}
