<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Connector that connects to a statically defined URI instead of the URI passed to the {@code connect()} call.
 */
final class StaticSocketConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly SocketAddress|string $uri,
        private readonly SocketConnector $connector,
    ) {
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        return $this->connector->connect($this->uri, $context, $cancellation);
    }
}
