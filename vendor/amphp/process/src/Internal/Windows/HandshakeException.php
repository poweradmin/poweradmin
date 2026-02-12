<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

/** @internal */
final class HandshakeException extends \Exception
{
    public function __construct(int $code = 0)
    {
        parent::__construct('Handshake failed', $code);
    }
}
