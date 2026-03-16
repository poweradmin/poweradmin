<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Dns\DnsRecord;
use function Amp\Socket\Internal\normalizeBindToOption;

final class ConnectContext
{
    private ?string $bindTo = null;

    private float $connectTimeout = 10;

    private ?int $typeRestriction = null;

    private bool $tcpNoDelay = false;

    private ?ClientTlsContext $tlsContext = null;

    public function withoutBindTo(): self
    {
        return $this->withBindTo(null);
    }

    public function withBindTo(?string $bindTo): self
    {
        $bindTo = normalizeBindToOption($bindTo);

        $clone = clone $this;
        $clone->bindTo = $bindTo;

        return $clone;
    }

    public function getBindTo(): ?string
    {
        return $this->bindTo;
    }

    public function withConnectTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new \ValueError("Invalid connect timeout ({$timeout}), must be greater than 0");
        }

        $clone = clone $this;
        $clone->connectTimeout = $timeout;

        return $clone;
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function withoutDnsTypeRestriction(): self
    {
        return $this->withDnsTypeRestriction(null);
    }

    public function withDnsTypeRestriction(?int $type): self
    {
        if ($type !== null && $type !== DnsRecord::AAAA && $type !== DnsRecord::A) {
            throw new \ValueError('Invalid resolver type restriction');
        }

        $clone = clone $this;
        $clone->typeRestriction = $type;

        return $clone;
    }

    public function getDnsTypeRestriction(): ?int
    {
        return $this->typeRestriction;
    }

    public function hasTcpNoDelay(): bool
    {
        return $this->tcpNoDelay;
    }

    public function withTcpNoDelay(): self
    {
        $clone = clone $this;
        $clone->tcpNoDelay = true;

        return $clone;
    }

    public function withoutTcpNoDelay(): self
    {
        $clone = clone $this;
        $clone->tcpNoDelay = false;

        return $clone;
    }

    public function withoutTlsContext(): self
    {
        return $this->withTlsContext(null);
    }

    public function withTlsContext(?ClientTlsContext $tlsContext): self
    {
        $clone = clone $this;
        $clone->tlsContext = $tlsContext;

        return $clone;
    }

    public function getTlsContext(): ?ClientTlsContext
    {
        return $this->tlsContext;
    }

    public function toStreamContextArray(): array
    {
        $options = [
            'tcp_nodelay' => $this->tcpNoDelay,
        ];

        if ($this->bindTo !== null) {
            $options['bindto'] = $this->bindTo;
        }

        $array = ['socket' => $options];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
