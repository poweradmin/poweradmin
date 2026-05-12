<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Process;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\splitLines;

final class WindowsDnsConfigLoader implements DnsConfigLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly HostLoader $hostLoader = new HostLoader(),
    ) {
    }

    public function loadConfig(): DnsConfig
    {
        $powershell = Process::start([
            'powershell',
            '-Command',
            'Get-WmiObject -Class Win32_NetworkAdapterConfiguration |
                Select-Object -ExpandProperty DNSServerSearchOrder',
        ]);

        if ($powershell->join() !== 0) {
            throw new DnsConfigException("Could not fetch DNS servers from WMI: " . buffer($powershell->getStderr()));
        }

        $output = \iterator_to_array(splitLines($powershell->getStdout()));

        $nameservers = \array_reduce($output, static function (array $nameservers, string $address): array {
            $ip = \inet_pton($address);

            if (isset($ip[15])) { // IPv6
                $nameservers[] = "[$address]:53";
            } elseif (isset($ip[3])) { // IPv4
                $nameservers[] = "$address:53";
            }

            return $nameservers;
        }, []);

        $hosts = $this->hostLoader->loadHosts();

        return new DnsConfig($nameservers, $hosts);
    }
}
