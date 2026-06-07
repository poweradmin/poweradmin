<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Worker\Internal\ContextWorker;
use function Amp\Parallel\Context\contextFactory;

/**
 * The built-in worker factory type.
 */
final class ContextWorkerFactory implements WorkerFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public const SCRIPT_PATH = __DIR__ . "/Internal/task-runner.php";

    public function __construct(
        private readonly ?string $bootstrapPath = null,
        private readonly ?ContextFactory $contextFactory = null,
    ) {
        if ($this->bootstrapPath !== null && !\file_exists($this->bootstrapPath)) {
            throw new \Error(\sprintf("No file found at bootstrap path given '%s'", $this->bootstrapPath));
        }
    }

    /**
     * The type of worker created depends on the extensions available. If multi-threading is enabled, a WorkerThread
     * will be created. If threads are not available a WorkerProcess will be created.
     */
    public function create(?Cancellation $cancellation = null): Worker
    {
        $script = [self::SCRIPT_PATH];

        if ($this->bootstrapPath !== null) {
            $script[] = $this->bootstrapPath;
        }

        return new ContextWorker(($this->contextFactory ?? contextFactory())->start($script, $cancellation));
    }
}
