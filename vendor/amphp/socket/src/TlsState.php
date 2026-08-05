<?php declare(strict_types=1);

namespace Amp\Socket;

enum TlsState
{
    case Disabled;
    case SetupPending;
    case Enabled;
    case ShutdownPending;
}
