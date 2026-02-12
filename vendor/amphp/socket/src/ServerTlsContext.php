<?php declare(strict_types=1);

namespace Amp\Socket;

final class ServerTlsContext
{
    public const TLSv1_0 = \STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
    public const TLSv1_1 = \STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
    public const TLSv1_2 = \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
    public const TLSv1_3 = \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;

    private const TLS_VERSIONS = [
        'TLSv1.0' => self::TLSv1_0,
        'TLSv1.1' => self::TLSv1_1,
        'TLSv1.2' => self::TLSv1_2,
        'TLSv1.3' => self::TLSv1_3,
    ];

    /**
     * @param resource $socket
     */
    public static function fromServerResource($socket): ?self
    {
        $tls = \stream_context_get_options($socket)['ssl'] ?? [];

        if (!$tls) {
            return null;
        }

        $context = (new self)
            ->withPeerName($tls['peer_name'])
            ->withVerificationDepth($tls['verify_depth'])
            ->withCiphers($tls['ciphers'])
            ->withSecurityLevel($tls['security_level'])
            ->withDefaultCertificate(new Certificate($tls['local_cert'], $tls['local_pk'] ?? null));

        if ($tls['verify_peer'] || $tls['verify_peer_name']) {
            $context = $context->withPeerVerification();
        }

        if ($tls['capture_peer_cert'] || $tls['capture_peer_chain']) {
            $context = $context->withPeerCapturing();
        }

        $minVersion = self::TLSv1_3;
        foreach ([self::TLSv1_2, self::TLSv1_1, self::TLSv1_0] as $tlsVersion) {
            if ($tls['crypto_method'] & $tlsVersion) {
                $minVersion = $tlsVersion;
            }
        }

        return $context->withMinimumVersion($minVersion);
    }

    private int $minVersion = self::TLSv1_2;

    private ?string $peerName = null;

    private bool $verifyPeer = false;

    private int $verifyDepth = 10;

    private ?string $ciphers = null;

    private ?string $caFile = null;

    private ?string $caPath = null;

    private bool $capturePeer = false;

    private ?Certificate $defaultCertificate = null;

    /** @var Certificate[] */
    private array $certificates = [];

    private int $securityLevel = 2;

    /** @var string[] */
    private array $alpnProtocols = [];

    /**
     * Minimum TLS version to negotiate.
     *
     * Defaults to TLS 1.2.
     *
     * @param int $version One of the `ServerTlsContext::TLSv*` constants.
     *
     * @return self Cloned, modified instance.
     * @throws \Error If an invalid minimum version is given.
     */
    public function withMinimumVersion(int $version): self
    {
        if (!\in_array($version, self::TLS_VERSIONS, true)) {
            throw new \Error(\sprintf(
                'Invalid minimum version, only %s allowed',
                \implode(', ', \array_keys(self::TLS_VERSIONS))
            ));
        }

        $clone = clone $this;
        $clone->minVersion = $version;

        return $clone;
    }

    /**
     * Returns the minimum TLS version to negotiate.
     */
    public function getMinimumVersion(): int
    {
        return $this->minVersion;
    }

    /**
     * Expected name of the peer.
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerName(?string $peerName = null): self
    {
        $clone = clone $this;
        $clone->peerName = $peerName;

        return $clone;
    }

    /**
     * @return null|string Expected name of the peer or `null` if such an expectation doesn't exist.
     */
    public function getPeerName(): ?string
    {
        return $this->peerName;
    }

    /**
     * Enable peer verification.
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerVerification(): self
    {
        $clone = clone $this;
        $clone->verifyPeer = true;

        return $clone;
    }

    /**
     * Disable peer verification, this is the default for servers.
     *
     * @return self Cloned, modified instance.
     */
    public function withoutPeerVerification(): self
    {
        $clone = clone $this;
        $clone->verifyPeer = false;

        return $clone;
    }

    /**
     * @return bool Whether peer verification is enabled.
     */
    public function hasPeerVerification(): bool
    {
        return $this->verifyPeer;
    }

    /**
     * Maximum chain length the peer might present including the certificates in the local trust store.
     *
     * @param int $verifyDepth Maximum length of the certificate chain.
     *
     * @return self Cloned, modified instance.
     */
    public function withVerificationDepth(int $verifyDepth): self
    {
        if ($verifyDepth < 0) {
            throw new \Error("Invalid verification depth ({$verifyDepth}), must be greater than or equal to 0");
        }

        $clone = clone $this;
        $clone->verifyDepth = $verifyDepth;

        return $clone;
    }

    /**
     * @return int Maximum length of the certificate chain.
     */
    public function getVerificationDepth(): int
    {
        return $this->verifyDepth;
    }

    /**
     * List of ciphers to negotiate, the server's order is always preferred.
     *
     * @param string|null $ciphers List of ciphers in OpenSSL's format (colon separated).
     *
     * @return self Cloned, modified instance.
     */
    public function withCiphers(?string $ciphers = null): self
    {
        $clone = clone $this;
        $clone->ciphers = $ciphers;

        return $clone;
    }

    /**
     * @return string List of ciphers in OpenSSL's format (colon separated).
     */
    public function getCiphers(): string
    {
        return $this->ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS;
    }

    /**
     * CAFile to check for trusted certificates.
     *
     * @param string|null $cafile Path to the file or `null` to unset.
     *
     * @return self Cloned, modified instance.
     */
    public function withCaFile(?string $cafile = null): self
    {
        $clone = clone $this;
        $clone->caFile = $cafile;

        return $clone;
    }

    /**
     * @return null|string Path to the trusted certificates file if one is set, otherwise `null`.
     */
    public function getCaFile(): ?string
    {
        return $this->caFile;
    }

    /**
     * CAPath to check for trusted certificates.
     *
     * @param string|null $capath Path to the directory or `null` to unset.
     *
     * @return self Cloned, modified instance.
     */
    public function withCaPath(?string $capath = null): self
    {
        $clone = clone $this;
        $clone->caPath = $capath;

        return $clone;
    }

    /**
     * @return null|string Path to the trusted certificate directory if one is set, otherwise `null`.
     */
    public function getCaPath(): ?string
    {
        return $this->caPath;
    }

    /**
     * Capture the certificates sent by the peer.
     *
     * Note: This is the chain as sent by the peer, NOT the verified chain.
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerCapturing(): self
    {
        $clone = clone $this;
        $clone->capturePeer = true;

        return $clone;
    }

    /**
     * Don't capture the certificates sent by the peer.
     *
     * @return self Cloned, modified instance.
     */
    public function withoutPeerCapturing(): self
    {
        $clone = clone $this;
        $clone->capturePeer = false;

        return $clone;
    }

    /**
     * @return bool Whether to capture the certificates sent by the peer.
     */
    public function hasPeerCapturing(): bool
    {
        return $this->capturePeer;
    }

    /**
     * Default certificate to use in case no SNI certificate matches.
     *
     * @return self Cloned, modified instance.
     */
    public function withDefaultCertificate(?Certificate $defaultCertificate = null): self
    {
        $clone = clone $this;
        $clone->defaultCertificate = $defaultCertificate;

        return $clone;
    }

    /**
     * @return Certificate|null Default certificate to use in case no SNI certificate matches, or `null` if unset.
     */
    public function getDefaultCertificate(): ?Certificate
    {
        return $this->defaultCertificate;
    }

    /**
     * Certificates to use for the given host names.
     *
     * @param array $certificates Must be a associative array mapping hostnames to certificate instances.
     *
     * @return self Cloned, modified instance.
     */
    public function withCertificates(array $certificates): self
    {
        foreach ($certificates as $key => $certificate) {
            if (!\is_string($key)) {
                throw new \TypeError('Expected an array mapping domain names to Certificate instances');
            }

            if (!$certificate instanceof Certificate) {
                throw new \TypeError('Expected an array of Certificate instances');
            }
        }

        $clone = clone $this;
        $clone->certificates = $certificates;

        return $clone;
    }

    /**
     * @return array Associative array mapping hostnames to certificate instances.
     */
    public function getCertificates(): array
    {
        return $this->certificates;
    }

    /**
     * Security level to use.
     *
     * Requires OpenSSL 1.1.0 or higher.
     *
     * @param int $level Must be between 0 and 5.
     *
     * @return self Cloned, modified instance.
     */
    public function withSecurityLevel(int $level): self
    {
        // See https://www.openssl.org/docs/manmaster/man3/SSL_CTX_set_security_level.html
        // Level 2 is not recommended, because of SHA-1 by that document,
        // but SHA-1 should be phased out now on general internet use.
        // We therefore default to level 2.

        if ($level < 0 || $level > 5) {
            throw new \Error("Invalid security level ({$level}), must be between 0 and 5.");
        }

        if (!hasTlsSecurityLevelSupport()) {
            throw new \Error("Can't set a security level, as PHP is compiled with OpenSSL < 1.1.0.");
        }

        $clone = clone $this;
        $clone->securityLevel = $level;

        return $clone;
    }

    /**
     * @return int Security level between 0 and 5. Always 0 for OpenSSL < 1.1.0.
     */
    public function getSecurityLevel(): int
    {
        // 0 is equivalent to previous versions of OpenSSL and just does nothing
        if (!hasTlsSecurityLevelSupport()) {
            return 0;
        }

        return $this->securityLevel;
    }

    /**
     * @param string[] $protocols
     *
     * @return self Cloned, modified instance.
     */
    public function withApplicationLayerProtocols(array $protocols): self
    {
        if (!hasTlsAlpnSupport()) {
            throw new \Error("Can't set an application layer protocol list, as PHP is compiled with OpenSSL < 1.0.2.");
        }

        foreach ($protocols as $protocol) {
            if (!\is_string($protocol)) {
                throw new \TypeError("Protocol names must be strings");
            }
        }

        $clone = clone $this;
        $clone->alpnProtocols = $protocols;

        return $clone;
    }

    /**
     * @return string[]
     */
    public function getApplicationLayerProtocols(): array
    {
        return $this->alpnProtocols;
    }

    /**
     * Converts this TLS context into PHP's equivalent stream context array.
     *
     * @return array Stream context array compatible with PHP's streams.
     */
    public function toStreamContextArray(): array
    {
        $options = [
            'crypto_method' => $this->toStreamCryptoMethod(),
            'peer_name' => $this->peerName,
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'verify_depth' => $this->verifyDepth,
            'ciphers' => $this->ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS,
            'honor_cipher_order' => true,
            'single_dh_use' => true,
            'no_ticket' => true,
            'capture_peer_cert' => $this->capturePeer,
            'capture_peer_chain' => $this->capturePeer,
        ];

        if (!empty($this->alpnProtocols)) {
            $options['alpn_protocols'] = \implode(',', $this->alpnProtocols);
        }

        if ($this->defaultCertificate !== null) {
            $options['local_cert'] = $this->defaultCertificate->getCertFile();

            if ($this->defaultCertificate->getCertFile() !== $this->defaultCertificate->getKeyFile()) {
                $options['local_pk'] = $this->defaultCertificate->getKeyFile();
            }
        }

        if ($this->certificates) {
            $options['SNI_server_certs'] = \array_map(static function (Certificate $certificate) {
                $options = [
                    'local_cert' => $certificate->getCertFile(),
                    'local_pk' => $certificate->getKeyFile(),
                ];

                if ($certificate->getPassphrase() !== null) {
                    $options['passphrase'] = $certificate->getPassphrase();
                }

                return $options;
            }, $this->certificates);
        }

        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }

        if ($this->caPath !== null) {
            $options['capath'] = $this->caPath;
        }

        if (hasTlsSecurityLevelSupport()) {
            $options['security_level'] = $this->securityLevel;
        }

        return ['ssl' => $options];
    }

    /**
     * @return int Crypto method compatible with PHP's streams.
     */
    public function toStreamCryptoMethod(): int
    {
        return match ($this->minVersion) {
            self::TLSv1_0 => self::TLSv1_0 | self::TLSv1_1 | self::TLSv1_2 | self::TLSv1_3,
            self::TLSv1_1 => self::TLSv1_1 | self::TLSv1_2 | self::TLSv1_3,
            self::TLSv1_2 => self::TLSv1_2 | self::TLSv1_3,
            self::TLSv1_3 => self::TLSv1_3,
            default => throw new \Error('Unknown minimum TLS version: ' . $this->minVersion),
        };
    }
}
