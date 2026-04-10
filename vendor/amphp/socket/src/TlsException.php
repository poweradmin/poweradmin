<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Thrown if TLS can't be properly negotiated or is not supported on the given socket.
 */
class TlsException extends SocketException
{
}
