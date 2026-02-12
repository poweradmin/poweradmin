<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class SignalCode
{
    public const HANDSHAKE = 0x01;
    public const HANDSHAKE_ACK = 0x02;
    public const CHILD_PID = 0x03;
    public const EXIT_CODE = 0x04;

    private function __construct()
    {
        // empty to prevent instances of this class
    }
}
