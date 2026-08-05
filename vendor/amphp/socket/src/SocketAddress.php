<?php declare(strict_types=1);

namespace Amp\Socket;

interface SocketAddress extends \Stringable
{
    public function toString(): string;

    public function getType(): SocketAddressType;
}
