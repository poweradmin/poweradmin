<?php declare(strict_types=1);

namespace Amp\Socket;

interface ServerSocketFactory
{
    /**
     * @throws SocketException
     */
    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ServerSocket;
}
