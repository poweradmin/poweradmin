<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;

/**
 * Interface for factories used to create new workers.
 */
interface WorkerFactory
{
    /**
     * Creates a new worker instance.
     *
     * @return Worker The newly created worker.
     */
    public function create(?Cancellation $cancellation = null): Worker;
}
