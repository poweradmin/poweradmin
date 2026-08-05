<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Worker\Internal;
use Amp\Pipeline\Queue;
use Amp\Serialization\SerializationException;
use Amp\Sync\Channel;
use Revolt\EventLoop;

return static function (Channel $channel) use ($argc, $argv): int {
    if (!\defined("AMP_WORKER")) {
        \define("AMP_WORKER", \AMP_CONTEXT);
    }

    if (isset($argv[1])) {
        if (!\is_file($argv[1])) {
            throw new \Error(\sprintf("No file found at bootstrap path given '%s'", $argv[1]));
        }

        // Include file within closure to protect scope.
        (function () use ($argc, $argv): void {
            /** @psalm-suppress UnresolvableInclude */
            require $argv[1];
        })();
    }

    /** @var array<string, DeferredCancellation> $cancellationSources */
    $cancellationSources = [];

    /** @var array<string, Queue> $queues */
    $queues = [];

    while ($data = $channel->receive()) {
        // New Task execution request.
        if ($data instanceof Internal\TaskSubmission) {
            $id = $data->getId();

            $cancellationSources[$id] = $source = new DeferredCancellation;
            $queues[$id] = $queue = new Queue();

            $jobChannel = new JobChannel($id, $channel, $queue->iterate());

            EventLoop::queue(static function () use (
                &$cancellationSources,
                &$queues,
                $data,
                $id,
                $source,
                $queue,
                $jobChannel,
                $channel,
            ): void {
                try {
                    $result = $data->getTask()->run($jobChannel, $source->getCancellation());

                    if ($result instanceof Future) {
                        $result = $result->await($source->getCancellation());
                    }

                    $result = new Internal\TaskSuccess($data->getId(), $result);
                } catch (\Throwable $exception) {
                    if ($exception instanceof CancelledException && $source->isCancelled()) {
                        $result = new Internal\TaskCancelled($id, $exception);
                    } else {
                        $result = new Internal\TaskFailure($id, $exception);
                    }
                } finally {
                    $queue->complete();
                    unset($cancellationSources[$id], $queues[$id]);
                }

                try {
                    $channel->send($result);
                } catch (SerializationException $exception) {
                    // Could not serialize task result.
                    $channel->send(new Internal\TaskFailure($id, $exception));
                }
            });
            continue;
        }

        // Channel message.
        if ($data instanceof Internal\JobMessage) {
            ($queues[$data->getId()] ?? null)?->pushAsync($data->getMessage())->ignore();
            continue;
        }

        // Cancellation signal.
        if ($data instanceof Internal\JobCancellation) {
            ($cancellationSources[$data->getId()] ?? null)?->cancel();
            continue;
        }

        // Should not happen, but just in case...
        throw new \Error('Invalid value ' . \get_debug_type($data) . ' received in ' . __FUNCTION__);
    }

    return 0;
};
