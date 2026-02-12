<?php declare(strict_types=1);

namespace Amp\Process;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\NullCancellation;
use Amp\Process\Internal\Posix\PosixRunner as PosixProcessRunner;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Internal\ProcessStreams;
use Amp\Process\Internal\ProcHolder;
use Amp\Process\Internal\Windows\WindowsRunner as WindowsProcessRunner;
use Revolt\EventLoop;

final class Process
{
    use ForbidCloning;
    use ForbidSerialization;

    private static \WeakMap $driverRunner;

    private static \WeakMap $procHolder;

    private static \WeakMap $streamHolder;

    /**
     * Starts a new process.
     *
     * @param string|list<string> $command Command to run.
     * @param string|null $workingDirectory Working directory, or an empty string to use the working directory of the
     *     parent.
     * @param array<string, string> $environment Environment variables, or use an empty array to inherit from the
     *     parent.
     * @param array<string, bool> $options Options for {@see proc_open()}.
     *
     * @throws ProcessException If starting the process fails.
     * @throws \Error If the arguments are invalid.
     */
    public static function start(
        string|array $command,
        ?string $workingDirectory = null,
        array $environment = [],
        array $options = [],
        ?Cancellation $cancellation = null,
    ): self {
        $envVars = [];
        foreach ($environment as $key => $value) {
            if (\is_array($value)) {
                throw new \Error('Argument #3 ($environment) cannot accept nested array values');
            }

            /** @psalm-suppress RedundantCastGivenDocblockType */
            $envVars[(string) $key] = (string) $value;
        }

        $command = \is_array($command)
            ? \implode(" ", \array_map(escapeArgument(...), $command))
            : $command;

        if ($workingDirectory === null) {
            $cwd = \getcwd();
            if ($cwd === false) {
                throw new ProcessException('Failed to determine current working directory');
            }

            $workingDirectory = $cwd;
        }

        $runner = self::getRunner();

        $context = $runner->start(
            $command,
            $cancellation ?? new NullCancellation(),
            $workingDirectory,
            $envVars,
            $options,
        );

        $handle = $context->handle;
        $streams = $context->streams;

        $procHolder = new ProcHolder($runner, $handle);
        self::$procHolder[$procHolder] = $handle->pid;

        self::$streamHolder[$streams->stdin] = $procHolder;
        self::$streamHolder[$streams->stdout] = $procHolder;
        self::$streamHolder[$streams->stderr] = $procHolder;

        return new self($runner, $handle, $streams, $command, $workingDirectory, $envVars, $options);
    }

    private static function getRunner(): ProcessRunner
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        self::$driverRunner ??= new \WeakMap();

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$procHolder)) {
            self::$procHolder = new \WeakMap();

            \register_shutdown_function(static function (): void {
                /** @var ProcHolder $procHolder */
                foreach (self::$procHolder as $procHolder => $pid) {
                    $procHolder->handle->wait();
                }
            });
        }

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        self::$streamHolder ??= new \WeakMap();

        $driver = EventLoop::getDriver();
        return self::$driverRunner[$driver] ??= \PHP_OS_FAMILY === 'Windows'
            ? new WindowsProcessRunner()
            : new PosixProcessRunner();
    }

    /**
     * @param array<string, string> $environment
     */
    private function __construct(
        private readonly ProcessRunner $runner,
        private readonly ProcessHandle $handle,
        private readonly ProcessStreams $streams,
        private readonly string $command,
        private readonly string $workingDirectory,
        private readonly array $environment = [],
        private readonly array $options = []
    ) {
    }

    /**
     * Wait for the process to end.
     *
     * @return int The process exit code.
     */
    public function join(?Cancellation $cancellation = null): int
    {
        return $this->runner->join($this->handle, $cancellation);
    }

    /**
     * Forcibly end the process.
     */
    public function kill(): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->runner->kill($this->handle);
    }

    /**
     * Send a signal to the process.
     *
     * @param int $signo Signal number to send to process.
     *
     * @throws ProcessException If signal sending is not supported.
     */
    public function signal(int $signo): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->runner->signal($this->handle, $signo);
    }

    /**
     * Returns the PID of the child process.
     */
    public function getPid(): int
    {
        return $this->handle->pid;
    }

    /**
     * Returns the command to execute.
     *
     * @return string The command to execute.
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Gets the current working directory.
     *
     * @return string The working directory.
     */
    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Gets the environment variables array.
     *
     * @return array<string, string> Array of environment variables.
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /**
     * Gets the options to pass to {@see proc_open()}.
     *
     * @return array<string, bool> Array of options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Determines if the process is still running.
     */
    public function isRunning(): bool
    {
        return $this->handle->status !== ProcessStatus::Ended;
    }

    /**
     * Gets the process input stream (STDIN).
     */
    public function getStdin(): WritableResourceStream
    {
        return $this->streams->stdin;
    }

    /**
     * Gets the process output stream (STDOUT).
     */
    public function getStdout(): ReadableResourceStream
    {
        return $this->streams->stdout;
    }

    /**
     * Gets the process error stream (STDERR).
     */
    public function getStderr(): ReadableResourceStream
    {
        return $this->streams->stderr;
    }

    /**
     * @return array{
     *     command: string,
     *     workingDirectory: string,
     *     environment: array<string, string>,
     *     options: array<string, bool>,
     *     pid: int,
     *     status: string,
     * }
     */
    public function __debugInfo(): array
    {
        return [
            'command' => $this->getCommand(),
            'workingDirectory' => $this->getWorkingDirectory(),
            'environment' => $this->getEnvironment(),
            'options' => $this->getOptions(),
            'pid' => $this->handle->pid,
            'status' => $this->isRunning() ? 'running' : 'terminated',
        ];
    }
}
