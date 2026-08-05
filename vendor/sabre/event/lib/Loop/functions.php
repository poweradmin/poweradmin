<?php

declare(strict_types=1);

namespace Sabre\Event\Loop;

/**
 * Executes a function after x seconds.
 */
function setTimeout(callable $cb, float $timeout): void
{
    instance()->setTimeout($cb, $timeout);
}

/**
 * Executes a function every x seconds.
 *
 * The value this function returns can be used to stop the interval with
 * clearInterval.
 *
 * @return array{0:string, 1:bool}
 */
function setInterval(callable $cb, float $timeout): array
{
    return instance()->setInterval($cb, $timeout);
}

/**
 * Stops a running interval.
 *
 * @param array{0:string, 1:bool} $intervalId
 */
function clearInterval(array $intervalId): void
{
    instance()->clearInterval($intervalId);
}

/**
 * Runs a function immediately at the next iteration of the loop.
 */
function nextTick(callable $cb): void
{
    instance()->nextTick($cb);
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
function addReadStream($stream, callable $cb): void
{
    instance()->addReadStream($stream, $cb);
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
function addWriteStream($stream, callable $cb): void
{
    instance()->addWriteStream($stream, $cb);
}

/**
 * Stop watching a stream for reads.
 *
 * @param resource $stream
 */
function removeReadStream($stream): void
{
    instance()->removeReadStream($stream);
}

/**
 * Stop watching a stream for writes.
 *
 * @param resource $stream
 */
function removeWriteStream($stream): void
{
    instance()->removeWriteStream($stream);
}

/**
 * Runs the loop.
 *
 * This function will run continuously, until there's no more events to
 * handle.
 */
function run(): void
{
    instance()->run();
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
function tick(bool $block = false): bool
{
    return instance()->tick($block);
}

/**
 * Stops a running eventloop.
 */
function stop(): void
{
    instance()->stop();
}

/**
 * Retrieves or sets the global Loop object.
 */
function instance(?Loop $newLoop = null): Loop
{
    static $loop;
    if ($newLoop instanceof Loop) {
        $loop = $newLoop;
    } elseif (!$loop) {
        $loop = new Loop();
    }

    return $loop;
}
