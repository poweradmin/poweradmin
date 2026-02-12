<?php declare(strict_types=1);

namespace Amp\Process\Internal;

/** @internal */
enum ProcessStatus
{
    case Starting;
    case Running;
    case Ended;
}
