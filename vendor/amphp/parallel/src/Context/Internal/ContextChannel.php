<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sync\Channel;

/**
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 *
 * @internal
 */
final class ContextChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly Channel $channel,
    ) {
    }

    public function send(mixed $data): void
    {
        $this->channel->send(new ContextMessage($data));
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        return $this->channel->receive($cancellation);
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->channel->onClose($onClose);
    }
}
