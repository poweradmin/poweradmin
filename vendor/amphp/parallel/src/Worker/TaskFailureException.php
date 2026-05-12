<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Parallel\Context\Internal;

final class TaskFailureException extends \Exception implements TaskFailureThrowable
{
    use Internal\ContextException;
}
