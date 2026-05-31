<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Internal\ProcessContext;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use const Amp\Process\BIN_DIR;

/**
 * @internal
 * @implements ProcessRunner<WindowsHandle>
 * @codeCoverageIgnore Windows only.
 * @psalm-suppress UndefinedConstant Psalm 5.4 may have a bug with conditionally defined constants.
 */
final class WindowsRunner implements ProcessRunner
{
    use ForbidCloning;
    use ForbidSerialization;

    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    private const WRAPPER_EXE_PATH = PHP_INT_SIZE === 8
        ? (BIN_DIR . '\\windows\\ProcessWrapper64.exe') : (BIN_DIR . '\\windows\\ProcessWrapper.exe');

    private static ?string $pharWrapperPath = null;

    private SocketConnector $socketConnector;

    public function __construct()
    {
        $this->socketConnector = new SocketConnector;
    }

    public function start(
        string $command,
        Cancellation $cancellation,
        ?string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): ProcessContext {
        if (\str_contains($command, "\0")) {
            throw new ProcessException("Can't execute commands that contain NUL bytes.");
        }

        $options['bypass_shell'] = true;

        \set_error_handler(static function (int $code, string $message): never {
            throw new ProcessException("Process could not be started: Errno: {$code}; {$message}");
        });

        try {
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            $proc = \proc_open(
                $this->makeCommand($workingDirectory ?? ''),
                self::FD_SPEC,
                $pipes,
                $workingDirectory ?: null,
                $environment ?: null,
                $options
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($proc)) {
            throw new ProcessException("Process could not be started: unknown error");
        }

        $status = \proc_get_status($proc);
        $handle = new WindowsHandle($proc);

        $securityTokens = \random_bytes(SocketConnector::SECURITY_TOKEN_SIZE * 6);
        $written = \fwrite($pipes[0], $securityTokens . "\0" . $command . "\0");

        \fclose($pipes[0]);
        \fclose($pipes[1]);

        if ($written !== SocketConnector::SECURITY_TOKEN_SIZE * 6 + \strlen($command) + 2) {
            \fclose($pipes[2]);
            \proc_terminate($proc);
            \proc_close($proc);

            throw new ProcessException("Could not send security tokens / command to process wrapper");
        }

        $handle->securityTokens = \str_split($securityTokens, SocketConnector::SECURITY_TOKEN_SIZE);
        $handle->wrapperPid = $status['pid'];

        try {
            $streams = $this->socketConnector->connectPipes($handle, $cancellation);
        } catch (\Exception) {
            $running = \is_resource($proc) && \proc_get_status($proc)['running'];

            $message = null;
            if (!$running) {
                $message = \stream_get_contents($pipes[2]);
            }

            \fclose($pipes[2]);
            \proc_terminate($proc);
            \proc_close($proc);

            $cancellation->throwIfRequested();

            /** @psalm-suppress RiskyTruthyFalsyComparison */
            throw new ProcessException(\trim($message ?: 'Process did not connect to server before timeout elapsed'));
        }

        return new ProcessContext($handle, $streams);
    }

    public function join(ProcessHandle $handle, ?Cancellation $cancellation = null): int
    {
        /** @var WindowsHandle $handle */
        $handle->exitCodeStream->reference();

        try {
            return $handle->joinDeferred->getFuture()->await($cancellation);
        } finally {
            $handle->exitCodeStream->unreference();
        }
    }

    public function kill(ProcessHandle $handle): void
    {
        /** @var WindowsHandle $handle */
        \exec('taskkill /F /T /PID ' . $handle->pid . ' 2>&1');
    }

    public function signal(ProcessHandle $handle, int $signal): void
    {
        throw new ProcessException('Signals are not supported on Windows');
    }

    public function destroy(ProcessHandle $handle): void
    {
        /** @var WindowsHandle $handle */
        if ($handle->status !== ProcessStatus::Ended && \getmypid() === $handle->originalParentPid) {
            try {
                $this->kill($handle);
            } catch (ProcessException) {
                // ignore
            }
        }
    }

    private function makeCommand(string $workingDirectory): string
    {
        $wrapperPath = self::WRAPPER_EXE_PATH;

        // We can't execute the exe from within the PHAR, so copy it out...
        if (\strncmp($wrapperPath, "phar://", 7) === 0) {
            if (self::$pharWrapperPath === null) {
                $fileHash = \hash_file('sha1', self::WRAPPER_EXE_PATH);
                self::$pharWrapperPath = \sys_get_temp_dir() . "/amphp-process-wrapper-" . $fileHash;

                if (
                    !\file_exists(self::$pharWrapperPath)
                    || \hash_file('sha1', self::$pharWrapperPath) !== $fileHash
                ) {
                    \copy(self::WRAPPER_EXE_PATH, self::$pharWrapperPath);
                }
            }

            $wrapperPath = self::$pharWrapperPath;
        }

        $result = \sprintf(
            '%s --address=%s --port=%d --token-size=%d',
            \escapeshellarg($wrapperPath),
            $this->socketConnector->address,
            $this->socketConnector->port,
            SocketConnector::SECURITY_TOKEN_SIZE
        );

        if ($workingDirectory !== '') {
            $result .= ' ' . \escapeshellarg('--cwd=' . \rtrim($workingDirectory, '\\'));
        }

        return $result;
    }
}
