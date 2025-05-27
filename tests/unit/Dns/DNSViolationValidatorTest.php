<?php

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Class DNSViolationValidatorTest
 */
#[CoversClass(DNSViolationValidator::class)]
class DNSViolationValidatorTest extends TestCase
{
    private MockObject&ConfigurationManager $configMock;
    private MockObject&PDOCommon $dbMock;
    private DNSViolationValidator $validator;
    private MockObject&\PDOStatement $pdoStatementMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->dbMock = $this->createMock(PDOCommon::class);

        // Set up the mock PDO statement that will be returned by prepare()
        $this->pdoStatementMock = $this->createMock(\PDOStatement::class);

        // Configure the mock to return our mock PDOStatement
        $this->dbMock->method('prepare')
            ->willReturn($this->pdoStatementMock);

        // Configure statement mock to handle bindParam calls
        $this->pdoStatementMock->method('bindParam')
            ->willReturn(true);

        // Configure execute to succeed
        $this->pdoStatementMock->method('execute')
            ->willReturn(true);

        $this->validator = new DNSViolationValidator($this->dbMock, $this->configMock);
    }

    /**
     * Test validation of records with no violations
     */
    public function testValidRecordWithNoViolations()
    {
        // Mock configuration
        $this->configMock->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('');

        // Configure statement mock to return no conflicts
        $this->pdoStatementMock->method('fetchColumn')
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
            ->with('database', 'pdns_db_name')
            ->willReturn('');

        // Configure statement mock to return no conflicts for both queries
        $this->pdoStatementMock->method('fetchColumn')
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
            ->with('database', 'pdns_db_name')
            ->willReturn('');

        // Configure statement mock to return a duplicate on first call (count of CNAME records)
        $this->pdoStatementMock->method('fetchColumn')
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
            ->with('database', 'pdns_db_name')
            ->willReturn('');

        // First call should return false (no duplicate CNAMEs), second call should return 'A' (conflicting record type)
        $this->pdoStatementMock->expects($this->exactly(2))
            ->method('fetchColumn')
            ->willReturnOnConsecutiveCalls(0, 'A');

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
            ->with('database', 'pdns_db_name')
            ->willReturn('');

        // Configure statement mock to return an existing CNAME record ID
        $this->pdoStatementMock->method('fetchColumn')
            ->willReturn(123);

        $result = $this->validator->validate(0, 1, RecordType::A, 'conflict.example.com', '192.168.1.1');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('conflicts with an existing CNAME record', $result->getFirstError());
    }
}
