<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class HostLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? $this->getDefaultPath();
    }

    public function loadHosts(): array
    {
        try {
            $contents = $this->readFile($this->path);
        } catch (DnsConfigException) {
            return [];
        }

        $data = [];

        $lines = \array_filter(\array_map("trim", \explode("\n", $contents)));

        foreach ($lines as $line) {
            if ($line[0] === "#") { // Skip comments
                continue;
            }

            $parts = \preg_split('/\s+/', $line);

            if (!($ip = \inet_pton($parts[0]))) {
                continue;
            }

            if (isset($ip[4])) {
                $key = DnsRecord::AAAA;
            } else {
                $key = DnsRecord::A;
            }

            for ($i = 1, $l = \count($parts); $i < $l; $i++) {
                try {
                    $normalizedName = normalizeName($parts[$i]);
                    $data[$key][$normalizedName] = $parts[0];
                } catch (InvalidNameException) {
                    // ignore invalid entries
                }
            }
        }

        return $data;
    }

    public function readFile(string $path): string
    {
        \set_error_handler(static function (int $errno, string $message) use ($path) {
            throw new DnsConfigException("Could not read configuration file '{$path}' ({$errno}): $message");
        });

        try {
            // Blocking file access, but this file should be local and usually loaded only once.
            return \file_get_contents($path);
        } finally {
            \restore_error_handler();
        }
    }

    private function getDefaultPath(): string
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts';
    }
}
