<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class StaticDnsConfigLoader implements DnsConfigLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly DnsConfig $config
    ) {
    }

    public function loadConfig(): DnsConfig
    {
        return $this->config;
    }
}
