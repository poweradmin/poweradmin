<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

use Amp\Parallel\Worker\Task;

/** @internal */
final class TaskSubmission extends JobPacket
{
    private static string $nextId = 'a';

    private Task|\__PHP_Incomplete_Class $task;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $id = self::$nextId;
        \PHP_VERSION_ID >= 80300 ? self::$nextId = \str_increment(self::$nextId) : ++self::$nextId;
        parent::__construct($id);
    }

    public function getTask(): Task
    {
        // Classes that cannot be autoloaded will be unserialized as an instance of __PHP_Incomplete_Class.
        if ($this->task instanceof \__PHP_Incomplete_Class) {
            throw new \Error(\sprintf(
                "Classes implementing %s must be autoloadable by the Composer autoloader",
                Task::class
            ));
        }

        return $this->task;
    }
}
