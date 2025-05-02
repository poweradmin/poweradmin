<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Registry for DNS record validators
 */
class DnsValidatorRegistry
{
    private array $validators = [];
    private ConfigurationManager $config;
    private PDOLayer $db;

    public function __construct(ConfigurationManager $config, PDOLayer $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->registerValidators();
    }

    /**
     * Register all supported validators
     */
    private function registerValidators(): void
    {
        // Register all validators (sorted alphabetically by record type)
        $this->validators = [
            RecordType::A => new ARecordValidator($this->config),
            RecordType::AAAA => new AAAARecordValidator($this->config),
            RecordType::AFSDB => new AFSDBRecordValidator($this->config),
            RecordType::ALIAS => new ALIASRecordValidator($this->config),
            RecordType::APL => new APLRecordValidator($this->config),
            RecordType::CNAME => new CNAMERecordValidator($this->config, $this->db),
            RecordType::CSYNC => new CSYNCRecordValidator($this->config),
            RecordType::DS => new DSRecordValidator($this->config),
            RecordType::HINFO => new HINFORecordValidator($this->config),
            RecordType::KX => new KXRecordValidator($this->config),
            RecordType::LOC => new LOCRecordValidator($this->config),
            RecordType::MX => new MXRecordValidator($this->config),
            RecordType::NS => new NSRecordValidator($this->config),
            RecordType::PTR => new PTRRecordValidator($this->config),
            RecordType::SOA => new SOARecordValidator($this->config, $this->db),
            RecordType::SPF => new SPFRecordValidator($this->config),
            RecordType::SRV => new SRVRecordValidator($this->config),
            RecordType::TXT => new TXTRecordValidator($this->config),
            RecordType::URI => new URIRecordValidator($this->config),
            // Add other validators for remaining record types as needed
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
        return $this->validators[$recordType] ?? new DefaultRecordValidator($this->config);
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
