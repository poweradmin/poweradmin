<?php declare(strict_types=1);

namespace Amp\Socket;

enum SocketAddressType
{
    case Internet;
    case Unix;
}
