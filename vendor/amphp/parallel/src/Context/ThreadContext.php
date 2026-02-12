<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\ByteStream\StreamChannel;
use Amp\Cancellation;
use Amp\Parallel\Context\Internal\AbstractContext;
use Amp\Parallel\Context\Internal\ParallelHub;
use Amp\Parallel\Ipc\IpcHub;
use Amp\TimeoutCancellation;
use parallel\Future as ParallelFuture;
use parallel\Runtime;
use parallel\Runtime\Error\Closed;
use Revolt\EventLoop;

/**
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @extends AbstractContext<TResult, TReceive, TSend>
 */
final class ThreadContext extends AbstractContext
{
    private const EXIT_CHECK_FREQUENCY = 0.25;
    private const DEFAULT_START_TIMEOUT = 5;

    private static ?\WeakMap $hubs = null;

    /** @var int Next thread ID. */
    private static int $nextId = 1;

    private static ?string $autoloadPath = null;

    /**
     * Checks if threading is enabled.
     *
     * @return bool True if threading is enabled, otherwise false.
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('parallel');
    }

    /**
     * @param string|non-empty-list<string> $script Path to PHP script or array with first element as path and
     *     following elements options to the PHP script (e.g.: ['bin/worker.php', 'Option1Value', 'Option2Value']).
     * @param positive-int $childConnectTimeout Number of seconds the thread will attempt to connect to the parent
     *      before failing.
     *
     * @throws ContextException If starting the process fails.
     */
    public static function start(
        IpcHub $ipcHub,
        string|array $script,
        ?Cancellation $cancellation = null,
        int $childConnectTimeout = self::DEFAULT_START_TIMEOUT
    ): self {
        /** @psalm-suppress RedundantFunctionCall */
        $script = \is_array($script) ? \array_values($script) : [$script];
        if (!$script) {
            throw new \ValueError('Empty script array provided to process context');
        }

        self::$hubs ??= new \WeakMap();
        $hub = (self::$hubs[EventLoop::getDriver()] ??= new Internal\ParallelHub());

        $key = $ipcHub->generateKey();

        if (self::$autoloadPath === null) {
            $paths = [
                \dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . "vendor" . \DIRECTORY_SEPARATOR . "autoload.php",
                \dirname(__DIR__, 4) . \DIRECTORY_SEPARATOR . "autoload.php",
            ];

            foreach ($paths as $path) {
                if (\file_exists($path)) {
                    self::$autoloadPath = $path;
                    break;
                }
            }

            if (self::$autoloadPath === null) {
                throw new \Error("Could not locate autoload.php");
            }
        }

        $id = self::$nextId++;

        $runtime = new Runtime(self::$autoloadPath);
        $future = $runtime->run(function (
            int $id,
            string $uri,
            string $key,
            float $connectTimeout,
            array $argv,
        ) {
            // @codeCoverageIgnoreStart
            // Only executed in thread.
            \define("AMP_CONTEXT", "parallel");
            \define("AMP_CONTEXT_ID", $id);

            EventLoop::unreference(EventLoop::repeat(self::EXIT_CHECK_FREQUENCY, function (): void {
                // Timer to give the chance for the PHP VM to be interrupted by Runtime::kill(), since system calls
                // such as select() will not be interrupted.
            }));

            Internal\runContext($uri, $key, new TimeoutCancellation($connectTimeout), $argv);

            return 0;
            // @codeCoverageIgnoreEnd
        }, [$id, $ipcHub->getUri(), $key, $childConnectTimeout, $script]);

        if (!$future) {
            $runtime->kill();
            throw new ContextException('Starting the thread did not return a future');
        }

        try {
            $socket = $ipcHub->accept($key, $cancellation);
            $ipcChannel = new StreamChannel($socket, $socket);

            $socket = $ipcHub->accept($key, $cancellation);
            $resultChannel = new StreamChannel($socket, $socket);
        } catch (\Throwable $exception) {
            $runtime->kill();

            $cancellation?->throwIfRequested();

            throw new ContextException("Starting the runtime failed", 0, $exception);
        }

        return new self($id, $runtime, $future, $hub, $ipcChannel, $resultChannel);
    }

    private readonly int $oid;

    private bool $exited = false;

    private function __construct(
        private readonly int $id,
        private readonly Runtime $runtime,
        ParallelFuture $future,
        private readonly ParallelHub $hub,
        StreamChannel $ipcChannel,
        StreamChannel $resultChannel,
    ) {
        parent::__construct($ipcChannel, $resultChannel);

        $exited = &$this->exited;
        $this->hub->add($this->id, $future)->finally(static function () use (&$exited): void {
            $exited = true;
        });

        $this->oid = \getmypid();
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if ($this->exited) {
            throw new ContextException('The thread has exited');
        }

        return parent::receive($cancellation);
    }

    public function send(mixed $data): void
    {
        if ($this->exited) {
            throw new ContextException('The thread has exited');
        }

        parent::send($data);
    }

    /**
     * Kills the thread if it is still running.
     */
    public function __destruct()
    {
        if (\getmypid() === $this->oid) {
            $this->close();
        }
    }

    public function close(): void
    {
        if (!$this->exited) {
            try {
                $this->runtime->kill();
            } catch (Closed) {
                // ignore
            }
        }

        $this->hub->remove($this->id);

        parent::close();
    }

    public function join(?Cancellation $cancellation = null): mixed
    {
        $data = $this->receiveExitResult($cancellation);

        $this->close();

        return $data->getResult();
    }
}
