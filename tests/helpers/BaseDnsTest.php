<?php

namespace TestHelpers;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationService;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\AAAARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HINFORecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\MINFORecordValidator;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\PTRRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TXTRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

/**
 * Base DNS test class with common setup for all DNS-related tests
 */
class BaseDnsTest extends SqliteDnsBackendTestCase
{

    protected DnsRecordValidationServiceInterface $validationService;
    protected SqlDnsBackendProvider $backendProvider;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDO::class);
        $configMock = $this->createMock(ConfigurationManager::class);
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);

        // Records the CNAME conflict checks see: two existing CNAMEs and one NS target
        $this->backendProvider = $this->sqliteBackendProvider([
            [123, 1, 'existing.cname.example.com', 'CNAME', 'target.example.com'],
            [456, 1, 'alias.example.com', 'CNAME', 'target.example.com'],
            [789, 1, 'example.com', 'NS', 'invalid.cname.target'],
        ]);

        // Configure the mock to return expected values
        $configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                // For DNS tests
                if ($group === 'dns' && $key === 'strict_tld_check') {
                    return true;
                }
                if ($group === 'dns' && $key === 'top_level_tld_check') {
                    return true;
                }

                // For database tests
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return 'pdns';  // Mock database name for tests
                }

                // Default return value
                return null;
            });

        // Mock database queries for DNS record validation tests
        $dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                if ($type === 'integer') {
                    return $value;
                }
                return "'$value'";
            });

        $dbMock->method('query')
            ->willReturnCallback(function ($query) {
                $stmtMock = $this->createMock(\PDOStatement::class);

                // Mock CNAME exists check
                if (strpos($query, "TYPE = 'CNAME'") !== false) {
                    if (strpos($query, "'existing.cname.example.com'") !== false) {
                        $stmtMock->method('fetch')->willReturn([123]); // Record exists
                    } elseif (strpos($query, "'alias.example.com'") !== false) {
                        $stmtMock->method('fetch')->willReturn([456]); // Record exists - CNAME exists for target
                    } else {
                        $stmtMock->method('fetch')->willReturn(false);
                    }
                } elseif (strpos($query, "type = 'MX'") !== false || strpos($query, "type = 'NS'") !== false) {
                    if (strpos($query, "'invalid.cname.target'") !== false) {
                        $stmtMock->method('fetch')->willReturn([123]); // Record exists - makes CNAME invalid
                    } else {
                        $stmtMock->method('fetch')->willReturn(false);
                    }
                } else {
                    $stmtMock->method('fetch')->willReturn(false); // No record found by default
                }

                return $stmtMock;
            });

        // Mock DomainRepository to return domain names
        $domainRepositoryMock->method('getDomainNameById')
            ->willReturnCallback(function ($zoneId) {
                if ($zoneId === 1) {
                    return 'example.com';
                } elseif ($zoneId === 2) {
                    return 'test.com';
                }
                return null;
            });

        // Create a mock for DnsValidatorRegistry
        $registryMock = $this->createMock(DnsValidatorRegistry::class);

        // Configure getValidator to return appropriate validator mocks
        $registryMock->method('getValidator')
            ->willReturnCallback(function ($type) use ($configMock) {
                // Create validator mock for each record type
                $validator = null;

                switch ($type) {
                    case RecordType::A:
                        $validator = new ARecordValidator($configMock);
                        break;
                    case RecordType::AAAA:
                        $validator = new AAAARecordValidator($configMock);
                        break;
                    case RecordType::CNAME:
                        $validator = new CNAMERecordValidator($configMock, $this->backendProvider);
                        break;
                    case RecordType::MX:
                        $validator = new MXRecordValidator($configMock);
                        break;
                    case RecordType::NS:
                        $validator = new NSRecordValidator($configMock);
                        break;
                    case RecordType::PTR:
                        $validator = new PTRRecordValidator($configMock);
                        break;
                    case RecordType::SOA:
                        $validator = new SOARecordValidator($configMock);
                        break;
                    case RecordType::TXT:
                        $validator = new TXTRecordValidator($configMock);
                        break;
                    case RecordType::SRV:
                        $validator = new SRVRecordValidator($configMock);
                        break;
                    case RecordType::SPF:
                        $validator = new SPFRecordValidator($configMock);
                        break;
                    case RecordType::HINFO:
                        $validator = new HINFORecordValidator($configMock);
                        break;
                    case RecordType::LOC:
                        $validator = new LOCRecordValidator($configMock);
                        break;
                    case RecordType::MINFO:
                        $validator = new MINFORecordValidator($configMock);
                        break;
                    case RecordType::DS:
                        $validator = new DSRecordValidator($configMock);
                        break;
                    case RecordType::CSYNC:
                        $validator = new CSYNCRecordValidator($configMock);
                        break;
                }

                return $validator;
            });

        $dnsCommonValidator = new DnsCommonValidator($this->backendProvider);
        $recordRepositoryMock = $this->createMock(RecordRepositoryInterface::class);
        $recordRepositoryMock->method('getRecordsByName')->willReturn([]);
        $dnsViolationValidator = new DNSViolationValidator($recordRepositoryMock);

        // Create validation service with mocked dependencies for tests
        $this->validationService = new DnsRecordValidationService(
            $registryMock,
            $dnsCommonValidator,
            $domainRepositoryMock,
            $dnsViolationValidator
        );
    }
}
