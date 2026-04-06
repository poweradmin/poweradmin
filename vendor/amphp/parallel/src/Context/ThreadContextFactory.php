<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;

final class ThreadContextFactory implements ContextFactory
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

    public function start(array|string $script, ?Cancellation $cancellation = null): ThreadContext
    {
        return ThreadContext::start($this->ipcHub, $script, $cancellation, $this->childConnectTimeout);
    }
}
