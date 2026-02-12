<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Parallel\Context\Internal;

final class TaskFailureError extends \Error implements TaskFailureThrowable
{
    use Internal\ContextException;
}
