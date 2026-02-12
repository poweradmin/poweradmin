<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\Closable;

interface ServerSocket extends Closable
{
    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?Socket;

    public function getAddress(): SocketAddress;

    public function getBindContext(): BindContext;
}
