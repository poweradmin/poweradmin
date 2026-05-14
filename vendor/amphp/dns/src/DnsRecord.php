<?php declare(strict_types=1);

namespace Amp\Dns;

use LibDNS\Records\ResourceQTypes;
use LibDNS\Records\ResourceTypes;

final class DnsRecord
{
    public const A = ResourceTypes::A;
    public const AAAA = ResourceTypes::AAAA;
    public const AFSDB = ResourceTypes::AFSDB;
    // public const APL = ResourceTypes::APL;
    public const CAA = ResourceTypes::CAA;
    public const CERT = ResourceTypes::CERT;
    public const CNAME = ResourceTypes::CNAME;
    public const DHCID = ResourceTypes::DHCID;
    public const DLV = ResourceTypes::DLV;
    public const DNAME = ResourceTypes::DNAME;
    public const DNSKEY = ResourceTypes::DNSKEY;
    public const DS = ResourceTypes::DS;
    public const HINFO = ResourceTypes::HINFO;
    // public const HIP = ResourceTypes::HIP;
    // public const IPSECKEY = ResourceTypes::IPSECKEY;
    public const KEY = ResourceTypes::KEY;
    public const KX = ResourceTypes::KX;
    public const ISDN = ResourceTypes::ISDN;
    public const LOC = ResourceTypes::LOC;
    public const MB = ResourceTypes::MB;
    public const MD = ResourceTypes::MD;
    public const MF = ResourceTypes::MF;
    public const MG = ResourceTypes::MG;
    public const MINFO = ResourceTypes::MINFO;
    public const MR = ResourceTypes::MR;
    public const MX = ResourceTypes::MX;
    public const NAPTR = ResourceTypes::NAPTR;
    public const NS = ResourceTypes::NS;
    // public const NSEC = ResourceTypes::NSEC;
    // public const NSEC3 = ResourceTypes::NSEC3;
    // public const NSEC3PARAM = ResourceTypes::NSEC3PARAM;
    public const NULL = ResourceTypes::NULL;
    public const PTR = ResourceTypes::PTR;
    public const RP = ResourceTypes::RP;
    // public const RRSIG = ResourceTypes::RRSIG;
    public const RT = ResourceTypes::RT;
    public const SIG = ResourceTypes::SIG;
    public const SOA = ResourceTypes::SOA;
    public const SPF = ResourceTypes::SPF;
    public const SRV = ResourceTypes::SRV;
    public const TXT = ResourceTypes::TXT;
    public const WKS = ResourceTypes::WKS;
    public const X25 = ResourceTypes::X25;

    public const AXFR = ResourceQTypes::AXFR;
    public const MAILB = ResourceQTypes::MAILB;
    public const MAILA = ResourceQTypes::MAILA;
    public const ALL = ResourceQTypes::ALL;

    /**
     * Converts a record type integer back into its name as defined in this class.
     *
     * Returns "unknown (<type>)" in case a name for this record is not known.
     *
     * @param int $type Record type as integer.
     *
     * @return string Name of the constant for this record in this class.
     */
    public static function getName(int $type): string
    {
        static $types;

        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        if ($types === null) {
            $types = \array_flip(
                (new \ReflectionClass(self::class))
                    ->getConstants()
            );
        }

        return $types[$type] ?? "unknown ({$type})";
    }

    public function __construct(
        private readonly string $value,
        private readonly int $type,
        private readonly ?int $ttl = null,
    ) {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }
}
