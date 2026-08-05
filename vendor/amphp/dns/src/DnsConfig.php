<?php declare(strict_types=1);

namespace Amp\Dns;

final class DnsConfig
{
    private array $nameservers;

    private array $knownHosts;

    private float $timeout = 3; // seconds.

    private int $attempts = 2;

    private array $searchList = [];

    private int $ndots = 1;

    private bool $rotation = false;

    /**
     * @throws DnsConfigException
     */
    public function __construct(array $nameservers, array $knownHosts = [])
    {
        if (\count($nameservers) < 1) {
            throw new DnsConfigException("At least one nameserver is required for a valid config");
        }

        foreach ($nameservers as $nameserver) {
            $this->validateNameserver($nameserver);
        }

        // Windows does not include localhost in its host file. Fetch it from the system instead
        if (!isset($knownHosts[DnsRecord::A]["localhost"]) && !isset($knownHosts[DnsRecord::AAAA]["localhost"])) {
            // PHP currently provides no way to **resolve** IPv6 hostnames (not even with fallback)
            $local = \gethostbyname("localhost");
            if ($local !== "localhost") {
                $knownHosts[DnsRecord::A]["localhost"] = $local;
            } else {
                $knownHosts[DnsRecord::AAAA]["localhost"] = '::1';
            }
        }

        $this->nameservers = $nameservers;
        $this->knownHosts = $knownHosts;
    }

    public function withSearchList(array $searchList): self
    {
        $self = clone $this;

        // Replace null with '.' for backward compatibility
        $self->searchList = \array_map(fn ($search) => $search ?? '.', $searchList);

        return $self;
    }

    /**
     * @throws DnsConfigException
     */
    public function withNdots(int $ndots): self
    {
        if ($ndots < 0) {
            throw new DnsConfigException("Invalid ndots ($ndots), must be greater or equal to 0");
        }

        $self = clone $this;
        $self->ndots = \min($ndots, 15);

        return $self;
    }

    public function withRotationEnabled(bool $enabled = true): self
    {
        $self = clone $this;
        $self->rotation = $enabled;

        return $self;
    }

    public function withTimeout(float $timeout): self
    {
        if ($timeout < 0) {
            throw new DnsConfigException("Invalid timeout ($timeout), must be 0 or greater");
        }

        $self = clone $this;
        $self->timeout = $timeout;

        return $self;
    }

    public function withAttempts(int $attempts): self
    {
        if ($attempts < 1) {
            throw new DnsConfigException("Invalid attempt count ($attempts), must be 1 or greater");
        }

        $self = clone $this;
        $self->attempts = $attempts;

        return $self;
    }

    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    public function getKnownHosts(): array
    {
        return $this->knownHosts;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getSearchList(): array
    {
        return $this->searchList;
    }

    public function getNdots(): int
    {
        return $this->ndots;
    }

    public function isRotationEnabled(): bool
    {
        return $this->rotation;
    }

    /**
     * @throws DnsConfigException
     */
    private function validateNameserver(string $nameserver): void
    {
        if ($nameserver[0] === "[") { // IPv6
            $addr = \strstr(\substr($nameserver, 1), "]", true);
            $addrEnd = \strrpos($nameserver, "]");
            if ($addrEnd === false) {
                throw new DnsConfigException("Invalid nameserver: $nameserver");
            }

            $port = \substr($nameserver, $addrEnd + 1);

            if ($port !== "" && !\preg_match("(^:(\\d+)$)", $port)) {
                throw new DnsConfigException("Invalid nameserver: $nameserver");
            }

            $port = $port === "" ? 53 : \substr($port, 1);
        } else { // IPv4
            $arr = \explode(":", $nameserver, 2);

            if (\count($arr) === 2) {
                [$addr, $port] = $arr;
            } else {
                $addr = $arr[0];
                $port = 53;
            }
        }

        $addr = \trim($addr, "[]");
        $port = (int) $port;

        if (!\inet_pton($addr)) {
            throw new DnsConfigException("Invalid server IP: $addr");
        }

        if ($port < 1 || $port > 65535) {
            throw new DnsConfigException("Invalid server port: $port");
        }
    }
}
