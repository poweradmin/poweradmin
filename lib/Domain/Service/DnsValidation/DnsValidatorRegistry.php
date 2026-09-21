<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Registry for DNS record validators
 */
class DnsValidatorRegistry
{
    private array $validators = [];
    private ConfigurationInterface $config;
    private DnsBackendProviderInterface $backendProvider;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationInterface $config, DnsBackendProviderInterface $backendProvider)
    {
        $this->config = $config;
        $this->backendProvider = $backendProvider;
        $this->hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($config));
        $this->registerValidators();
    }

    /**
     * Register all supported validators
     */
    private function registerValidators(): void
    {
        // Register all validators (sorted alphabetically by record type)
        $this->validators = [
            RecordType::A => new ARecordValidator($this->hostnameValidator),
            RecordType::AAAA => new AAAARecordValidator($this->hostnameValidator),
            RecordType::AFSDB => new AFSDBRecordValidator($this->hostnameValidator),
            RecordType::ALIAS => new ALIASRecordValidator($this->hostnameValidator),
            RecordType::APL => new APLRecordValidator($this->hostnameValidator, $this->config),
            RecordType::BRID => new BRIDRecordValidator($this->hostnameValidator),
            RecordType::CAA => new CAARecordValidator($this->hostnameValidator),
            RecordType::CDNSKEY => new CDNSKEYRecordValidator($this->hostnameValidator),
            RecordType::CDS => new CDSRecordValidator($this->hostnameValidator),
            RecordType::CERT => new CERTRecordValidator($this->hostnameValidator),
            RecordType::CNAME => new CNAMERecordValidator($this->hostnameValidator, $this->backendProvider),
            RecordType::CSYNC => new CSYNCRecordValidator($this->hostnameValidator),
            RecordType::DHCID => new DHCIDRecordValidator($this->hostnameValidator),
            RecordType::DLV => new DLVRecordValidator($this->hostnameValidator),
            RecordType::DNAME => new DNAMERecordValidator($this->hostnameValidator),
            RecordType::DNSKEY => new DNSKEYRecordValidator($this->hostnameValidator),
            RecordType::DS => new DSRecordValidator($this->hostnameValidator),
            RecordType::EUI48 => new EUI48RecordValidator($this->hostnameValidator),
            RecordType::EUI64 => new EUI64RecordValidator($this->hostnameValidator),
            RecordType::HHIT => new HHITRecordValidator($this->hostnameValidator),
            RecordType::HINFO => new HINFORecordValidator($this->hostnameValidator),
            RecordType::HTTPS => new HTTPSRecordValidator($this->hostnameValidator),
            RecordType::IPSECKEY => new IPSECKEYRecordValidator($this->hostnameValidator),
            RecordType::KEY => new KEYRecordValidator($this->hostnameValidator),
            RecordType::KX => new KXRecordValidator($this->hostnameValidator),
            RecordType::L32 => new L32RecordValidator($this->hostnameValidator),
            RecordType::L64 => new L64RecordValidator($this->hostnameValidator),
            RecordType::LOC => new LOCRecordValidator($this->hostnameValidator),
            RecordType::LP => new LPRecordValidator($this->hostnameValidator),
            RecordType::LUA => new LUARecordValidator($this->hostnameValidator),
            RecordType::MINFO => new MINFORecordValidator($this->hostnameValidator),
            RecordType::MR => new MRRecordValidator($this->hostnameValidator),
            RecordType::MX => new MXRecordValidator($this->hostnameValidator),
            RecordType::NAPTR => new NAPTRRecordValidator($this->hostnameValidator),
            RecordType::NID => new NIDRecordValidator(),
            RecordType::NS => new NSRecordValidator($this->hostnameValidator),
            RecordType::NSEC => new NSECRecordValidator($this->hostnameValidator),
            RecordType::NSEC3 => new NSEC3RecordValidator($this->hostnameValidator),
            RecordType::NSEC3PARAM => new NSEC3PARAMRecordValidator($this->hostnameValidator),
            RecordType::OPENPGPKEY => new OPENPGPKEYRecordValidator($this->hostnameValidator),
            RecordType::PTR => new PTRRecordValidator($this->hostnameValidator),
            RecordType::RESINFO => new RESINFORecordValidator($this->hostnameValidator),
            RecordType::RKEY => new RKEYRecordValidator($this->hostnameValidator),
            RecordType::RP => new RPRecordValidator($this->hostnameValidator),
            RecordType::RRSIG => new RRSIGRecordValidator($this->hostnameValidator),
            RecordType::SMIMEA => new SMIMEARecordValidator($this->hostnameValidator),
            RecordType::SOA => new SOARecordValidator($this->hostnameValidator, $this->config),
            RecordType::SPF => new SPFRecordValidator($this->hostnameValidator),
            RecordType::SRV => new SRVRecordValidator($this->hostnameValidator),
            RecordType::SSHFP => new SSHFPRecordValidator($this->hostnameValidator),
            RecordType::SVCB => new SVCBRecordValidator($this->hostnameValidator),
            RecordType::TKEY => new TKEYRecordValidator($this->hostnameValidator),
            RecordType::TLSA => new TLSARecordValidator($this->hostnameValidator),
            RecordType::TSIG => new TSIGRecordValidator($this->hostnameValidator),
            RecordType::TXT => new TXTRecordValidator($this->hostnameValidator),
            RecordType::URI => new URIRecordValidator($this->hostnameValidator),
            RecordType::WALLET => new WALLETRecordValidator($this->hostnameValidator),
            RecordType::ZONEMD => new ZONEMDRecordValidator($this->hostnameValidator),
        ];

        // For record types not yet implemented, we'll use DefaultRecordValidator
        // which is created on-demand in the getValidator method
    }

    /**
     * Get validator for a specific record type
     *
     * @param string $recordType The record type (A, AAAA, CNAME, etc.)
     * @return DnsRecordValidatorInterface The validator instance (default validator if specific one not found)
     */
    public function getValidator(string $recordType): DnsRecordValidatorInterface
    {
        return $this->validators[$recordType] ?? new DefaultRecordValidator();
    }

    /**
     * Check if a validator exists for a record type
     *
     * @param string $recordType The record type
     * @return bool True if validator exists (always true now since we use DefaultRecordValidator for unknown types)
     */
    public function hasValidator(string $recordType): bool
    {
        return true;
    }
}
