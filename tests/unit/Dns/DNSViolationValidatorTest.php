<?php

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Class DNSViolationValidatorTest
 *
 * @covers \Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator
 */
class DNSViolationValidatorTest extends TestCase
{
    private $configMock;
    private $dbMock;
    private $validator;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->validator = new DNSViolationValidator($this->dbMock, $this->configMock);
    }

    /**
     * Test validation of records with no violations
     */
    public function testValidRecordWithNoViolations()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_name')
            ->willReturn('');

        // Mock database query for validateConflictsWithCNAME
        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate(0, 1, RecordType::A, 'example.com', '192.168.1.1');
        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation of a CNAME record with no violations
     */
    public function testValidCNAMERecordWithNoViolations()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_name')
            ->willReturn('');

        // Mock database queries for CNAME validation
        $this->dbMock->expects($this->exactly(2))
            ->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate(0, 1, RecordType::CNAME, 'alias.example.com', 'target.example.com');
        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with duplicate CNAME records
     */
    public function testDuplicateCNAMERecord()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_name')
            ->willReturn('');

        // Mock database query for checkDuplicateCNAME (finds a duplicate)
        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(1);

        $result = $this->validator->validate(0, 1, RecordType::CNAME, 'alias.example.com', 'target.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Multiple CNAME records with the same name are not allowed', $result->getFirstError());
    }

    /**
     * Test validation with a CNAME record conflicting with other types
     */
    public function testCNAMEConflictWithOtherTypes()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_name')
            ->willReturn('');

        // Mock database query results - first query (duplicate CNAME) returns false, second query (other type) returns 'A'
        $this->dbMock->expects($this->exactly(2))
            ->method('queryOne')
            ->willReturnOnConsecutiveCalls(false, 'A');

        $result = $this->validator->validate(0, 1, RecordType::CNAME, 'conflict.example.com', 'target.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A CNAME record cannot coexist with other record types', $result->getFirstError());
    }

    /**
     * Test validation with a record conflicting with an existing CNAME
     */
    public function testRecordConflictsWithExistingCNAME()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_name')
            ->willReturn('');

        // Mock database query (finds a conflicting CNAME)
        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(123); // Existing CNAME record ID

        $result = $this->validator->validate(0, 1, RecordType::A, 'conflict.example.com', '192.168.1.1');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('conflicts with an existing CNAME record', $result->getFirstError());
    }
}
