<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;

final class ProcessContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param string|null $workingDirectory Working directory.
     * @param array<string, string> $environment Array of environment variables, or use an empty array to inherit from
     *     the parent.
     * @param string|non-empty-list<string>|null $binary Path to PHP binary or array of binary path and options.
     *      Null will attempt to automatically locate the binary.
     * @param positive-int $childConnectTimeout Number of seconds the child will attempt to connect to the parent
     *      before failing.
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly array $environment = [],
        private readonly string|array|null $binary = null,
        private readonly int $childConnectTimeout = 5,
        private readonly IpcHub $ipcHub = new LocalIpcHub(),
    ) {
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @throws ContextException
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): ProcessContext
    {
        return ProcessContext::start(
            ipcHub: $this->ipcHub,
            script: $script,
            workingDirectory: $this->workingDirectory,
            environment: $this->environment,
            cancellation: $cancellation,
            binary: $this->binary,
            childConnectTimeout: $this->childConnectTimeout,
        );
    }
}
