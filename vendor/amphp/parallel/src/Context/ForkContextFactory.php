<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;

/**
 * USE AT YOUR OWN RISK!
 *
 * Forking is not recommended at arbitrary points in an application since the entire state of the parent process is
 * inherited into the child process, including the event-loop!
 *
 * We recommend using {@see DefaultContextFactory} or {@see ProcessContextFactory} for general-purpose applications.
 */
final class ForkContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param positive-int $childConnectTimeout Number of seconds the child will attempt to connect to the parent
     *      before failing.
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(
        private readonly int $childConnectTimeout = 5,
        private readonly IpcHub $ipcHub = new LocalIpcHub(),
    ) {
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @throws ContextException
     */
    #[\Override]
    public function start(string|array $script, ?Cancellation $cancellation = null): ForkContext
    {
        return ForkContext::start(
            ipcHub: $this->ipcHub,
            script: $script,
            cancellation: $cancellation,
            childConnectTimeout: $this->childConnectTimeout,
        );
    }
}
