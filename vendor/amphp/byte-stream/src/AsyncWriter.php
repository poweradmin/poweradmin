<?php declare(strict_types=1);

namespace Amp\ByteStream;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * This class provides a tool for efficiently writing to a stream asynchronously. A single fiber is used for all
 * writes to the stream, while each write returns a {@see Future} instead of waiting for each write to complete before
 * returning control to the caller.
 */
final class AsyncWriter
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?WritableStream $destination;

    /** @var \SplQueue<array{DeferredFuture, string|null}> */
    private readonly \SplQueue $writeQueue;

    /** @var Suspension<bool>|null */
    private ?Suspension $suspension = null;

    public function __construct(WritableStream $destination)
    {
        $this->destination = $destination;
        $this->writeQueue = $writeQueue = new \SplQueue;

        $suspension = &$this->suspension;
        EventLoop::queue(static function () use ($writeQueue, $destination, &$suspension): void {
            while ($destination->isWritable()) {
                if ($writeQueue->isEmpty()) {
                    $suspension = EventLoop::getSuspension();
                    if (!$suspension->suspend()) {
                        return;
                    }
                }

                self::dequeue($writeQueue, $destination);
            }
        });
    }

    private static function dequeue(\SplQueue $writeQueue, WritableStream $destination): void
    {
        while (!$writeQueue->isEmpty()) {
            /**
             * @var DeferredFuture $deferredFuture
             * @var string|null $bytes
             */
            [$deferredFuture, $bytes] = $writeQueue->dequeue();

            try {
                if ($bytes !== null) {
                    $destination->write($bytes);
                } else {
                    $destination->end();
                }

                $deferredFuture->complete();
            } catch (\Throwable $exception) {
                $deferredFuture->error($exception);
                while (!$writeQueue->isEmpty()) {
                    [$deferredFuture] = $writeQueue->dequeue();
                    $deferredFuture->error($exception);
                }
                return;
            }
        }
    }

    public function __destruct()
    {
        $this->destination = null;
        $this->suspension?->resume(false);
        $this->suspension = null;
    }

    /**
     * Queues a chunk of data to be written to the stream, returning a {@see Future} that is completed once the data
     * has been written to the stream or errors if it cannot be written to the stream.
     *
     * @return Future<never>
     */
    public function write(string $bytes): Future
    {
        return $this->send($bytes);
    }

    /**
     * Closes the underlying WritableStream once all queued data has been written.
     *
     * @return Future<void>
     */
    public function end(): Future
    {
        return $this->send(null);
    }

    private function send(?string $bytes): Future
    {
        if (!$this->isWritable()) {
            return Future::error(new ClosedException('The destination stream is no longer writable'));
        }

        if ($bytes === null) {
            $this->destination = null;
        }

        $deferredFuture = new DeferredFuture();
        $this->writeQueue->enqueue([$deferredFuture, $bytes]);
        $this->suspension?->resume(true);
        $this->suspension = null;

        return $deferredFuture->getFuture();
    }

    public function isWritable(): bool
    {
        return (bool) $this->destination?->isWritable();
    }
}
