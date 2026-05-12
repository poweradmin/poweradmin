<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\Parallel\Worker\Task;

/**
 * @internal
 *
 * @template-covariant T
 * @template-extends TaskResult<T>
 */
final class TaskSuccess extends TaskResult
{
    /**
     * @param T $result
     */
    public function __construct(
        string $id,
        private readonly mixed $result
    ) {
        parent::__construct($id);
    }

    /**
     * @return T
     */
    public function getResult(): mixed
    {
        if ($this->result instanceof \__PHP_Incomplete_Class) {
            throw new \Error(\sprintf(
                "Class instances returned from %s::run() must be autoloadable by the Composer autoloader",
                Task::class
            ));
        }

        return $this->result;
    }
}
