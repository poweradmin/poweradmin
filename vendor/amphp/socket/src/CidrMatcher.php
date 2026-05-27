<?php declare(strict_types=1);

namespace Amp\Socket;

final class CidrMatcher
{
    private static function toIPv6(string $networkAddress): string
    {
        if (\strlen($networkAddress) === 4) {
            // IPv4-mapped IPv6 address: https://www.rfc-editor.org/rfc/rfc4038#section-4.2
            $networkAddress = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF" . $networkAddress;
        }

        \assert(\strlen($networkAddress) * 8 === 128);

        return $networkAddress;
    }

    private readonly string $address;
    private readonly string $mask;

    public function __construct(string $cidr)
    {
        [$networkAddress, $bits] = $this->parse($cidr);

        $binMask = \str_split(\str_repeat('1', $bits) . \str_repeat('0', 128 - $bits), 8);

        /** @psalm-suppress InvalidScalarArgument */
        $mask = \implode("", \array_map(fn ($byte) => \chr(\bindec($byte)), $binMask));

        $this->address = $networkAddress & $mask;
        $this->mask = $mask;
    }

    private function parse(string $cidr): array
    {
        [$address, $bits] = \explode("/", $cidr, 2) + [null, null];

        $networkAddress = \inet_pton($address);
        $ipv4 = \strlen($networkAddress) === 4;

        $bits ??= $ipv4 ? '32' : '128';

        return [self::toIPv6($networkAddress), (int) $bits + ($ipv4 ? 96 : 0)];
    }

    public function match(string $ip): bool
    {
        $networkAddress = self::toIPv6(\inet_pton($ip));

        return ($networkAddress & $this->mask) === $this->address;
    }
}
