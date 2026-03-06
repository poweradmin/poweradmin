<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Sync\Channel;

/**
 * @template-covariant TResult
 * @template TReceive
 * @template TSend
 */
final class Execution
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param Task<TResult, TReceive, TSend> $task
     * @param Channel<TSend, TReceive> $channel
     * @param Future<TResult> $future
     */
    public function __construct(
        private readonly Task $task,
        private readonly Channel $channel,
        private readonly Future $future,
    ) {
    }

    /**
     * @return Task<TResult, TReceive, TSend>
     */
    public function getTask(): Task
    {
        return $this->task;
    }

    /**
     * Communication channel to the task. The other end of this channel is provided to {@see Task::run()}.
     *
     * @return Channel<TSend, TReceive>
     */
    public function getChannel(): Channel
    {
        return $this->channel;
    }

    /**
     * Return value from {@see Task::run()}.
     *
     * @return Future<TResult>
     */
    public function getFuture(): Future
    {
        return $this->future;
    }

    /**
     * Shortcut to calling {@see self::getFuture()::await()}. Cancellation only cancels awaiting the result, it does
     * not cancel the task. Use the cancellation passed to {@see Worker::submit()} to cancel the task.
     *
     * @return TResult
     */
    public function await(?Cancellation $cancellation = null): mixed
    {
        return $this->future->await($cancellation);
    }
}
