<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\ByteStream\StreamChannel;
use Amp\Cancellation;
use Amp\Parallel\Context\Internal\AbstractContext;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

/**
 * USE AT YOUR OWN RISK! This context is not used by default in {@see DefaultContextFactory} because the timing of its
 * creation must be purposeful and situational.
 *
 * Forking is not recommended at arbitrary points in an application since the entire state of the parent process is
 * inherited into the child process, including the event-loop!
 *
 * Forking very early in an application, may be beneficial to copy state from the parent that was expensive to set up.
 *
 * This context is NOT compatible with event-loop extensions such as ext-uv or ext-ev.
 *
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @extends AbstractContext<TResult, TReceive, TSend>
 */
final class ForkContext extends AbstractContext
{
    private const DEFAULT_START_TIMEOUT = 5;

    public static function isSupported(): bool
    {
        return \extension_loaded('pcntl')
            && \extension_loaded('posix')
            && \function_exists('pcntl_fork') // pcntl_fork may be disabled.
            && EventLoop::getDriver()->getHandle() === null;
    }

    /**
     * @param string|non-empty-list<string> $script Path to PHP script or array with first element as path and
     *     following elements options to the PHP script (e.g.: ['bin/worker.php', 'Option1Value', 'Option2Value']).
     * @param positive-int $childConnectTimeout Number of seconds the child will attempt to connect to the parent
     *      before failing.
     *
     * @throws ContextException If starting the process fails.
     */
    public static function start(
        IpcHub $ipcHub,
        string|array $script,
        ?Cancellation $cancellation = null,
        int $childConnectTimeout = self::DEFAULT_START_TIMEOUT,
        Serializer $serializer = new NativeSerializer(),
    ): self {
        $key = $ipcHub->generateKey();

        // Fork
        if (($pid = \pcntl_fork()) < 0) {
            throw new ContextException("Forking failed: " . \posix_strerror(\posix_get_last_error()));
        }

        // Parent
        if ($pid > 0) {
            try {
                $socket = $ipcHub->accept($key, $cancellation);
                $ipcChannel = new StreamChannel($socket, $socket, $serializer);

                $socket = $ipcHub->accept($key, $cancellation);
                $resultChannel = new StreamChannel($socket, $socket, $serializer);
            } catch (\Throwable $exception) {
                $cancellation?->throwIfRequested();

                throw new ContextException("Connecting failed after forking", previous: $exception);
            }

            return new self($pid, $ipcChannel, $resultChannel);
        }

        // Child
        \define("AMP_CONTEXT", "fork");
        \define("AMP_CONTEXT_ID", \getmypid());

        if (\is_string($script)) {
            $script = [$script];
        }

        $connectCancellation = new TimeoutCancellation((float) $childConnectTimeout);
        Internal\runContext($ipcHub->getUri(), $key, $connectCancellation, $script, $serializer);

        exit(0);
    }

    private ?int $exited = null;

    private bool $weKilled = false;

    /**
     * @param StreamChannel<TReceive, TSend> $ipcChannel
     */
    private function __construct(
        private readonly int $pid,
        StreamChannel $ipcChannel,
        StreamChannel $resultChannel,
    ) {
        parent::__construct($ipcChannel, $resultChannel);
    }

    #[\Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        $this->checkExit(false); // Will throw if the process exited unexpectedly.

        return parent::receive($cancellation);
    }

    #[\Override]
    public function send(mixed $data): void
    {
        $this->checkExit(false); // Will throw if the process exited unexpectedly.

        parent::send($data);
    }

    private function checkExit(bool $wait): ?int
    {
        if ($this->exited === null) {
            if (\pcntl_waitpid($this->pid, $status, $wait ? 0 : \WNOHANG) === 0) {
                return null;
            }

            $this->exited = match (true) {
                \pcntl_wifsignaled($status) => \pcntl_wtermsig($status),
                \pcntl_wifexited($status) => \pcntl_wexitstatus($status) - 128,
                \pcntl_wifstopped($status) => \pcntl_wstopsig($status),
                default => -1,
            };
        }

        if (!$this->weKilled && $this->exited > 0) {
            throw new ContextException("Worker exited due to signal {$this->exited}", $this->exited);
        }

        return $this->exited;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->checkExit(false) === null) {
            $this->weKilled = true;
            \posix_kill($this->pid, \SIGKILL);

            $this->checkExit(true);
        }

        parent::close();
    }

    #[\Override]
    public function join(?Cancellation $cancellation = null): mixed
    {
        $result = $this->receiveExitResult($cancellation);

        return $result->getResult();
    }
}
