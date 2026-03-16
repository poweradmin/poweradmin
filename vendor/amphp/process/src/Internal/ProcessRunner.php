<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\Cancellation;
use Amp\Process\ProcessException;

/**
 * @internal
 * @template THandle extends ProcessHandle
 */
interface ProcessRunner
{
    /**
     * Start a process using the supplied parameters.
     *
     * @param string $command The command to execute.
     * @param string|null $workingDirectory The working directory for the child process.
     * @param array $environment Environment variables to pass to the child process.
     * @param array $options `proc_open()` options.
     *
     * @throws ProcessException If starting the process fails.
     */
    public function start(
        string $command,
        Cancellation $cancellation,
        ?string $workingDirectory = null,
        array $environment = [],
        array $options = [],
    ): ProcessContext;

    /**
     * Wait for the child process to end.
     *
     * @param THandle $handle The process descriptor.
     *
     * @return int Exit code.
     */
    public function join(ProcessHandle $handle, ?Cancellation $cancellation = null): int;

    /**
     * Forcibly end the child process.
     *
     * @param THandle $handle The process descriptor.
     *
     * @throws ProcessException If terminating the process fails.
     */
    public function kill(ProcessHandle $handle): void;

    /**
     * Send a signal to the child process.
     *
     * @param THandle $handle The process descriptor.
     * @param int $signal Signal number to send to process.
     *
     * @throws ProcessException If sending the signal fails.
     */
    public function signal(ProcessHandle $handle, int $signal): void;

    /**
     * Release all resources held by the process handle.
     *
     * @param THandle $handle The process descriptor.
     */
    public function destroy(ProcessHandle $handle): void;
}
