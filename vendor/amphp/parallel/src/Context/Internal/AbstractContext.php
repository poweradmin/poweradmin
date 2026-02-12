<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use function Amp\async;
use function Amp\Parallel\Context\flattenArgument;

/**
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @implements Context<TResult, TReceive, TSend>
 */
abstract class AbstractContext implements Context
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var Future<ExitResult<TResult>>|null */
    private ?Future $result = null;

    protected function __construct(
        private readonly Channel $ipcChannel,
        private readonly Channel $resultChannel,
    ) {
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        try {
            $data = $this->ipcChannel->receive($cancellation);
        } catch (ChannelException $exception) {
            $this->ipcChannel->close();

            throw new ContextException(
                "The context stopped responding, potentially due to a fatal error or calling exit",
                previous: $exception,
            );
        }

        if (!$data instanceof ContextMessage) {
            if ($data instanceof ExitResult) {
                $data = $data->getResult();

                throw new ContextException(\sprintf(
                    'Context unexpectedly exited when waiting to receive data with result: %s',
                    flattenArgument($data),
                ));
            }

            throw new ContextException(\sprintf(
                'Unexpected data type from context: %s',
                flattenArgument($data),
            ));
        }

        return $data->getMessage();
    }

    public function send(mixed $data): void
    {
        try {
            $this->ipcChannel->send($data);
        } catch (ChannelException $exception) {
            $this->ipcChannel->close();

            throw new ContextException(
                "The context stopped responding, potentially due to a fatal error or calling exit",
                previous: $exception,
            );
        }
    }

    public function close(): void
    {
        $this->ipcChannel->close();
    }

    public function isClosed(): bool
    {
        return $this->ipcChannel->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->ipcChannel->onClose($onClose);
    }

    protected function receiveExitResult(?Cancellation $cancellation = null): ExitResult
    {
        while ($this->result) {
            try {
                $this->result->await($cancellation);
            } catch (CancelledException) {
                // Ignore cancellation from a prior join request, throw only if this request was cancelled.
                $cancellation?->throwIfRequested();
            }
        }

        $this->result = async(function () use ($cancellation): ExitResult {
            try {
                $data = $this->resultChannel->receive($cancellation);
                $this->resultChannel->close();
            } catch (CancelledException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->resultChannel->close();
                throw new ContextException("Failed to receive result from context", previous: $exception);
            }

            if (!$data instanceof ExitResult) {
                throw new ContextException(\sprintf(
                    "The context sent data instead of exiting: %s",
                    flattenArgument($data),
                ));
            }

            return $data;
        });

        try {
            return $this->result->await();
        } catch (CancelledException $exception) {
            $this->result = null;
            throw $exception;
        }
    }
}
