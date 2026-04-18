<?php declare(strict_types=1);

namespace Amp\Dns;

/**
 * MUST be thrown in case the config can't be read and no fallback is available.
 */
class DnsConfigException extends DnsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
