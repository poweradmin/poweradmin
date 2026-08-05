<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterator;

/** @internal */
interface IntermediateOperation
{
    public function __invoke(ConcurrentIterator $source): ConcurrentIterator;
}
