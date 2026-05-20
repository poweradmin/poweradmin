<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;
use function Amp\async;

final class DefaultContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly ContextFactory $contextFactory;

    /**
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(IpcHub $ipcHub = new LocalIpcHub())
    {
        if (ThreadContext::isSupported()) {
            $this->contextFactory = new ThreadContextFactory(ipcHub: $ipcHub);
        } else {
            $this->contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);
        }
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @throws ContextException
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        $context = $this->contextFactory->start($script, $cancellation);

        if ($context instanceof ProcessContext) {
            $stdout = $context->getStdout();
            $stdout->unreference();

            $stderr = $context->getStderr();
            $stderr->unreference();

            async(ByteStream\pipe(...), $stdout, ByteStream\getStdout())->ignore();
            async(ByteStream\pipe(...), $stderr, ByteStream\getStderr())->ignore();
        }

        return $context;
    }
}
