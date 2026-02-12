<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/** @internal */
final class ProcessStreams
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        public readonly WritableResourceStream $stdin,
        public readonly ReadableResourceStream $stdout,
        public readonly ReadableResourceStream $stderr,
    ) {
    }
}
