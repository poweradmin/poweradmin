<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * @see ServerTlsContext::withDefaultCertificate()
 * @see ServerTlsContext::withCertificates()
 */
final class Certificate
{
    private readonly string $certFile;
    private readonly string $keyFile;
    private readonly ?string $passphase;

    /**
     * @param string      $certFile Certificate file with the certificate + intermediaries.
     * @param string|null $keyFile Key file with the corresponding private key or `null` if the key is in $certFile.
     */
    public function __construct(string $certFile, ?string $keyFile = null, ?string $passphrase = null)
    {
        $this->certFile = $certFile;
        $this->keyFile = $keyFile ?? $certFile;
        $this->passphase = $passphrase;
    }

    public function getCertFile(): string
    {
        return $this->certFile;
    }

    public function getKeyFile(): string
    {
        return $this->keyFile;
    }

    public function getPassphrase(): ?string
    {
        return $this->passphase;
    }
}
