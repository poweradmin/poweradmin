<?php declare(strict_types=1);

namespace Amp\Parallel\Context;

use Amp\Cancellation;
use Amp\Sync\Channel;

/**
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @extends Channel<TReceive, TSend>
 */
interface Context extends Channel
{
    /**
     * @return TResult The data returned from the context. This method may be called at any time to await the result or
     *      an exception will be thrown if the context is closed or throws an exception or exits with a non-zero code.
     *
     * @throws ContextException If the context exited with an uncaught exception or non-zero code.
     */
    public function join(?Cancellation $cancellation = null): mixed;
}
