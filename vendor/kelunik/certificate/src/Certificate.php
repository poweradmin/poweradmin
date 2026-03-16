<?php declare(strict_types=1);

namespace Kelunik\Certificate;

class Certificate
{
    public static function derToPem($der)
    {
        if (!\is_string($der)) {
            throw new \InvalidArgumentException("\$der must be a string, " . \gettype($der) . " given.");
        }

        return \sprintf(
            "-----BEGIN CERTIFICATE-----\n%s-----END CERTIFICATE-----\n",
            \chunk_split(\base64_encode($der), 64, "\n")
        );
    }

    public static function pemToDer($pem)
    {
        if (!\is_string($pem)) {
            throw new \InvalidArgumentException("\$pem must be a string, " . \gettype($pem) . " given.");
        }

        $pattern = "@-----BEGIN CERTIFICATE-----\n([a-zA-Z0-9+/=\n]+)-----END CERTIFICATE-----@";

        if (!\preg_match($pattern, $pem, $match)) {
            throw new InvalidCertificateException("Invalid PEM could not be converted to DER format.");
        }

        return \base64_decode(\str_replace(["\n", "\r"], "", \trim($match[1])));
    }

    private $pem;
    private $info;
    private $issuer;
    private $subject;

    public function __construct($pem)
    {
        if (\is_string($pem)) {
            if (!$cert = @\openssl_x509_read($pem)) {
                throw new InvalidCertificateException("Invalid PEM encoded certificate!");
            }
        } elseif ($pem instanceof \OpenSSLCertificate) {
            $cert = $pem;
        } elseif (\is_resource($pem)) {
            if (\get_resource_type($pem) !== "OpenSSL X.509") {
                throw new InvalidCertificateException("Invalid resource of type other than 'OpenSSL X.509'!");
            }

            $cert = $pem;
        } else {
            throw new \InvalidArgumentException("Invalid variable type, expected string|resource, got " . \gettype($pem));
        }

        if (\openssl_x509_export($pem, $this->pem) === false) {
            throw new InvalidCertificateException("Could not convert 'OpenSSL X.509' resource to PEM!");
        }

        if (!$this->info = \openssl_x509_parse($cert)) {
            throw new InvalidCertificateException("Invalid PEM encoded certificate!");
        }
    }

    public function getNames()
    {
        $san = isset($this->info["extensions"]["subjectAltName"]) ? $this->info["extensions"]["subjectAltName"] : "";
        $names = [];

        $parts = \array_map("trim", \explode(",", $san));

        foreach ($parts as $part) {
            if (\stripos($part, "dns:") === 0) {
                $names[] = \substr($part, 4);
            }
        }

        $names = \array_map("strtolower", $names);
        $names = \array_unique($names);

        \sort($names);

        return $names;
    }

    public function getSubject()
    {
        if ($this->subject === null) {
            $this->subject = new Profile(
                isset($this->info["subject"]["CN"]) ? $this->info["subject"]["CN"] : null,
                isset($this->info["subject"]["O"]) ? $this->info["subject"]["O"] : null,
                isset($this->info["subject"]["C"]) ? $this->info["subject"]["C"] : null
            );
        }

        return $this->subject;
    }

    public function getIssuer()
    {
        if ($this->issuer === null) {
            $this->issuer = new Profile(
                isset($this->info["issuer"]["CN"]) ? $this->info["issuer"]["CN"] : null,
                isset($this->info["issuer"]["O"]) ? $this->info["issuer"]["O"] : null,
                isset($this->info["issuer"]["C"]) ? $this->info["issuer"]["C"] : null
            );
        }

        return $this->issuer;
    }

    public function getSerialNumber()
    {
        return $this->info["serialNumber"];
    }

    public function getValidFrom()
    {
        return $this->info["validFrom_time_t"];
    }

    public function getValidTo()
    {
        return $this->info["validTo_time_t"];
    }

    public function getSignatureType()
    {
        // https://3v4l.org/Iu3T2
        if (!isset($this->info["signatureTypeSN"])) {
            throw new FieldNotSupportedException("Signature type is not supported in this version of PHP. Please update your version to a higher bugfix version. See: https://3v4l.org/Iu3T2");
        }

        return $this->info["signatureTypeSN"];
    }

    public function isSelfSigned()
    {
        return $this->info["subject"] === $this->info["issuer"];
    }

    public function toPem()
    {
        return $this->pem;
    }

    public function toDer()
    {
        return self::pemToDer($this->pem);
    }

    public function __toString()
    {
        return $this->pem;
    }

    public function __debugInfo()
    {
        return [
            "commonName" => $this->getSubject()->getCommonName(),
            "names" => $this->getNames(),
            "issuedBy" => $this->getIssuer()->getCommonName(),
            "validFrom" => \date("d.m.Y", $this->getValidFrom()),
            "validTo" => \date("d.m.Y", $this->getValidTo()),
        ];
    }
}
