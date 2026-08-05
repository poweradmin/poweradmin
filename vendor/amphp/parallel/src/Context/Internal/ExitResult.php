<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\Parallel\Context\ContextException;

/**
 * @internal
 * @template-covariant TValue
 */
interface ExitResult
{
    /**
     * @return TValue Return value of the callable given to the execution context.
     *
     * @throws ContextException If the context exited with an uncaught exception.
     */
    public function getResult(): mixed;
}
