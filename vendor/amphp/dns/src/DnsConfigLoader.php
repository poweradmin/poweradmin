<?php declare(strict_types=1);

namespace Amp\Dns;

interface DnsConfigLoader
{
    /**
     * @throws DnsConfigException
     */
    public function loadConfig(): DnsConfig;
}
