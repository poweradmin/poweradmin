<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\Closable;

interface UdpSocket extends Closable
{
    /**
     * @param positive-int|null $limit Read at most $limit bytes from the datagram socket. {@code null} uses an
     *     implementation defined limit.
     *
     * @return array{InternetAddress, string}|null Returns {@code null} if the socket is closed.
     *
     * @throws PendingReceiveError If a reception request is already pending.
     */
    public function receive(?Cancellation $cancellation = null, ?int $limit = null): ?array;

    /**
     * @throws SocketException If the UDP socket closes before the data can be sent or the payload is too large.
     */
    public function send(InternetAddress $address, string $data): void;

    public function getAddress(): InternetAddress;
}
