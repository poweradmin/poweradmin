<?php declare(strict_types=1);

namespace Amp\Socket;

final class InternetAddress implements SocketAddress
{
    /**
     * @throws SocketException Thrown if the address or port is invalid.
     */
    public static function fromString(string $address): self
    {
        if (!\str_contains($address, ':')) {
            throw new SocketException('Missing port in address: ' . $address);
        }

        return self::tryFromString($address)
            ?? throw new SocketException('Invalid address: ' . $address);
    }

    /**
     * @return self|null Returns null if the address is invalid.
     */
    public static function tryFromString(string $address): ?self
    {
        $colon = \strrpos($address, ':');
        if ($colon === false) {
            return null;
        }

        $ip = \substr($address, 0, $colon);
        $port = (int) \substr($address, $colon + 1);

        if ($port < 0 || $port > 65535) {
            return null;
        }

        if (\strrpos($ip, ':')) {
            $ip = \trim($ip, '[]');
        }

        if (!\inet_pton($ip)) {
            return null;
        }

        return new self($ip, $port);
    }

    private readonly string $binaryAddress;

    private readonly string $textualAddress;

    /** @var int<0, 65535> */
    private readonly int $port;

    /**
     * @param int<0, 65535> $port
     *
     * @throws SocketException If an invalid address or port is given.
     */
    public function __construct(string $address, int $port)
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($port < 0 || $port > 65535) {
            throw new SocketException('Port number must be an integer between 0 and 65535; got ' . $port);
        }

        if (\strrpos($address, ':')) {
            $address = \trim($address, '[]');
        }

        $binaryAddress = \inet_pton($address);
        if ($binaryAddress === false) {
            throw new SocketException('Invalid address: ' . $address);
        }

        $this->binaryAddress = $binaryAddress;

        $this->textualAddress = \inet_ntop($binaryAddress);
        $this->port = $port;
    }

    public function getType(): SocketAddressType
    {
        return SocketAddressType::Internet;
    }

    public function getAddress(): string
    {
        return $this->textualAddress;
    }

    public function getAddressBytes(): string
    {
        return $this->binaryAddress;
    }

    public function getVersion(): InternetAddressVersion
    {
        if (\strlen($this->binaryAddress) === 4) {
            return InternetAddressVersion::IPv4;
        }

        return InternetAddressVersion::IPv6;
    }

    /**
     * @return int<0, 65535>
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return non-empty-string <address>:<port> formatted string.
     */
    public function toString(): string
    {
        if ($this->getVersion() === InternetAddressVersion::IPv6) {
            return '[' . $this->textualAddress . ']' . ':' . $this->port;
        }

        return $this->textualAddress . ':' . $this->port;
    }

    /**
     * @see toString
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
