<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/** @internal  */
final class ProcessContext
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        public readonly ProcessHandle $handle,
        public readonly ProcessStreams $streams,
    ) {
    }
}
