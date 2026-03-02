<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\CancelledException;
use Amp\Parallel\Worker\TaskCancelledException;

/** @internal */
final class TaskCancelled extends TaskFailure
{
    public function __construct(string $id, CancelledException $exception)
    {
        parent::__construct($id, $exception);
    }

    /**
     * @throws TaskCancelledException
     */
    public function getResult(): never
    {
        throw new TaskCancelledException($this->createException());
    }
}
