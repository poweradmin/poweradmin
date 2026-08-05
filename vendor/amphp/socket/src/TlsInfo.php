<?php declare(strict_types=1);

namespace Amp\Socket;

use Kelunik\Certificate\Certificate;

/**
 * Exposes a connection's negotiated TLS parameters.
 */
final class TlsInfo
{
    /** @var Certificate[]|null */
    private ?array $parsedCertificates = null;

    /**
     * Constructs a new instance from a stream socket resource.
     *
     * @param resource $resource Stream socket resource.
     *
     * @return self|null Returns null if TLS is not enabled on the stream socket.
     */
    public static function fromStreamResource($resource): ?self
    {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \Error("Expected a valid stream resource");
        }

        $metadata = \stream_get_meta_data($resource)['crypto'] ?? [];
        $tlsContext = \stream_context_get_options($resource)['ssl'] ?? [];

        return empty($metadata) ? null : self::fromMetaData($metadata, $tlsContext);
    }

    /**
     * Constructs a new instance from PHP's internal info.
     *
     * Always pass the info as obtained from PHP as this method might extract additional fields in the future.
     *
     * @param array $cryptoInfo Crypto info obtained via `stream_get_meta_data($socket->getResource())["crypto"]`.
     * @param array $tlsContext Context obtained via `stream_context_get_options($socket->getResource())["ssl"])`.
     */
    public static function fromMetaData(array $cryptoInfo, array $tlsContext): self
    {
        if (isset($tlsContext["peer_certificate"])) {
            $certificates = \array_merge([$tlsContext["peer_certificate"]], $tlsContext["peer_certificate_chain"] ?? []);
        } else {
            $certificates = $tlsContext["peer_certificate_chain"] ?? [];
        }

        return new self(
            $cryptoInfo["protocol"],
            $cryptoInfo["cipher_name"],
            $cryptoInfo["cipher_bits"],
            $cryptoInfo["cipher_version"],
            $cryptoInfo["alpn_protocol"] ?? null,
            empty($certificates) ? null : $certificates
        );
    }

    /**
     * @param array<resource>|null $certificates
     */
    private function __construct(
        private readonly string $version,
        private readonly string $cipherName,
        private readonly int $cipherBits,
        private readonly string $cipherVersion,
        private readonly ?string $alpnProtocol,
        private readonly ?array $certificates,
    ) {
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCipherName(): string
    {
        return $this->cipherName;
    }

    public function getCipherBits(): int
    {
        return $this->cipherBits;
    }

    public function getCipherVersion(): string
    {
        return $this->cipherVersion;
    }

    public function getApplicationLayerProtocol(): ?string
    {
        return $this->alpnProtocol;
    }

    /**
     * @return Certificate[]
     *
     * @throws SocketException If peer certificates were not captured.
     */
    public function getPeerCertificates(): array
    {
        if ($this->certificates === null) {
            throw new SocketException("Peer certificates not captured; use ClientTlsContext::withPeerCapturing() to capture peer certificates");
        }

        return $this->parsedCertificates ??= \array_map(
            static fn ($resource) => new Certificate($resource),
            $this->certificates,
        );
    }
}
