<?php

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
        // Register all validators
        $this->validators = [
            RecordType::A => new ARecordValidator($this->config),
            RecordType::AAAA => new AAAARecordValidator($this->config),
            RecordType::CNAME => new CNAMERecordValidator($this->config, $this->db),
            RecordType::CSYNC => new CSYNCRecordValidator($this->config),
            RecordType::DS => new DSRecordValidator($this->config),
            RecordType::HINFO => new HINFORecordValidator($this->config),
            RecordType::LOC => new LOCRecordValidator($this->config),
            RecordType::SOA => new SOARecordValidator($this->config, $this->db),
            RecordType::SPF => new SPFRecordValidator($this->config),
            RecordType::SRV => new SRVRecordValidator($this->config),
            RecordType::TXT => new TXTRecordValidator($this->config),
            RecordType::MX => new MXRecordValidator($this->config),
            RecordType::NS => new NSRecordValidator($this->config),
            RecordType::PTR => new PTRRecordValidator($this->config),
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
