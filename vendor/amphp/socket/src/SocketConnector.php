<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;

interface SocketConnector
{
    /**
     * Establish a socket connection to the specified URI.
     *
     * @param SocketAddress|string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
     * @param ConnectContext|null $context Socket connect context to use when connecting.
     *
     * @throws ConnectException
     * @throws CancelledException
     */
    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket;
}
