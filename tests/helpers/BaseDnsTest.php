<?php

namespace TestHelpers;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
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
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\TXTRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Base DNS test class with common setup for all DNS-related tests
 */
class BaseDnsTest extends TestCase
{
    protected DnsRecordValidationServiceInterface $validationService;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDOCommon::class);
        $configMock = $this->createMock(ConfigurationManager::class);
        $messageServiceMock = $this->createMock(MessageService::class);
        $zoneRepositoryMock = $this->createMock(ZoneRepositoryInterface::class);

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
                if ($group === 'database' && $key === 'pdns_name') {
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
                    } else {
                        $stmtMock->method('fetch')->willReturn(false);
                    }
                }
                // Mock MX/NS check for CNAME validation
                elseif (strpos($query, "type = 'MX'") !== false || strpos($query, "type = 'NS'") !== false) {
                    if (strpos($query, "'invalid.cname.target'") !== false) {
                        $stmtMock->method('fetch')->willReturn([123]); // Record exists - makes CNAME invalid
                    } else {
                        $stmtMock->method('fetch')->willReturn(false);
                    }
                }
                // Mock target is alias check
                elseif (strpos($query, "TYPE = 'CNAME'") !== false) {
                    if (strpos($query, "'alias.example.com'") !== false) {
                        $stmtMock->method('fetch')->willReturn([456]); // Record exists - CNAME exists for target
                    } else {
                        $stmtMock->method('fetch')->willReturn(false);
                    }
                } else {
                    $stmtMock->method('fetch')->willReturn(false); // No record found by default
                }

                return $stmtMock;
            });

        // Mock ZoneRepository to return domain names
        $zoneRepositoryMock->method('getDomainNameById')
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
            ->willReturnCallback(function ($type) use ($configMock, $dbMock) {
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
                        $validator = $this->getMockBuilder(CNAMERecordValidator::class)
                            ->setConstructorArgs([$configMock, $dbMock])
                            ->onlyMethods(['isValidCnameExistence'])
                            ->getMock();

                        $validator->method('isValidCnameExistence')
                            ->willReturnCallback(function ($hostname, $rid) {
                                // Return false for known problematic hostnames
                                if ($hostname === 'existing.cname.example.com') {
                                    return false;
                                }
                                return true;
                            });

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
                        $validator = $this->getMockBuilder(SOARecordValidator::class)
                            ->setConstructorArgs([$configMock, $dbMock])
                            ->onlyMethods(['setSOAParams'])
                            ->getMock();
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

        $ttlValidator = new TTLValidator();
        $dnsCommonValidator = new DnsCommonValidator($dbMock, $configMock);
        $dnsViolationValidator = new DNSViolationValidator($dbMock, $configMock);

        // Create validation service with mocked dependencies for tests
        $this->validationService = new DnsRecordValidationService(
            $registryMock,
            $dnsCommonValidator,
            $ttlValidator,
            $messageServiceMock,
            $zoneRepositoryMock,
            $dnsViolationValidator
        );
    }
}
