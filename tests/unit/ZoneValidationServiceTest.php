<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Tests for ZoneValidationService
 *
 * Tests pre-flight zone validation checks before DNSSEC signing operations.
 */
class ZoneValidationServiceTest extends TestCase
{
    private $dbMock;
    private ZoneValidationService $validator;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOCommon::class);
        $this->validator = new ZoneValidationService($this->dbMock);
    }

    /**
     * Helper method to create a PDO statement mock
     */
    private function createStatementMock(array $returnData): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($returnData);
        $stmt->method('fetch')->willReturn($returnData[0] ?? false);
        return $stmt;
    }

    /**
     * Test validating a zone with valid configuration
     */
    public function testValidZonePassesValidation(): void
    {
        // Mock SOA record query
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400']
        ]);

        // Mock NS record query - return apex NS records
        $nsStmt = $this->createStatementMock([
            ['name' => 'example.com'],
            ['name' => 'example.com']
        ]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['issues']);
    }

    /**
     * Test missing SOA record detection
     */
    public function testMissingSoaRecordDetected(): void
    {
        // Mock SOA record query (empty)
        $soaStmt = $this->createStatementMock([]);
        $nsStmt = $this->createStatementMock([['name' => 'example.com']]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('missing_soa', $result['issues'][0]['type']);
        $this->assertEquals('critical', $result['issues'][0]['severity']);
    }

    /**
     * Test multiple SOA records detection
     */
    public function testMultipleSoaRecordsDetected(): void
    {
        // Mock SOA record query (multiple records)
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400'],
            ['id' => 2, 'name' => 'example.com', 'content' => 'ns2.example.com hostmaster.example.com 2024010101 28800 7200 604800 86400']
        ]);
        $nsStmt = $this->createStatementMock([['name' => 'example.com']]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('multiple_soa', $result['issues'][0]['type']);
        $this->assertEquals('error', $result['issues'][0]['severity']);
    }

    /**
     * Test missing apex NS records detection
     */
    public function testMissingApexNsRecordsDetected(): void
    {
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400']
        ]);
        // Mock NS query - no NS records at all
        $nsStmt = $this->createStatementMock([]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('missing_apex_ns', $result['issues'][0]['type']);
        $this->assertEquals('error', $result['issues'][0]['severity']);
    }

    /**
     * Test that delegation NS records don't count as apex NS
     */
    public function testDelegationNsRecordsNotCountedAsApex(): void
    {
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400']
        ]);
        // Mock NS query - only delegation NS records (child.example.com)
        $nsStmt = $this->createStatementMock([
            ['name' => 'child.example.com'],
            ['name' => 'sub.example.com']
        ]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('missing_apex_ns', $result['issues'][0]['type']);
        $this->assertEquals('error', $result['issues'][0]['severity']);
        $this->assertStringContainsString('apex', $result['issues'][0]['message']);
    }

    /**
     * Test formatted error message generation
     */
    public function testFormattedErrorMessageGeneration(): void
    {
        $validationResult = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'missing_soa',
                    'severity' => 'critical',
                    'message' => 'No SOA record present',
                    'suggestion' => 'Add an SOA record'
                ],
                [
                    'type' => 'missing_ns',
                    'severity' => 'error',
                    'message' => 'Zone has no NS records',
                    'suggestion' => 'Add NS records for your authoritative name servers'
                ]
            ]
        ];

        $message = $this->validator->getFormattedErrorMessage($validationResult);

        $this->assertStringContainsString('DNSSEC signing cannot proceed', $message);
        $this->assertStringContainsString('No SOA record present', $message);
        $this->assertStringContainsString('Zone has no NS records', $message);
        $this->assertStringContainsString('Add an SOA record', $message);
        $this->assertStringContainsString('Add NS records', $message);
    }

    /**
     * Test formatted error message for valid zone
     */
    public function testFormattedErrorMessageForValidZone(): void
    {
        $validationResult = [
            'valid' => true,
            'issues' => []
        ];

        $message = $this->validator->getFormattedErrorMessage($validationResult);

        $this->assertEmpty($message);
    }

    /**
     * Test SOA record not at apex detection
     */
    public function testSoaNotAtApexDetected(): void
    {
        // Mock SOA record query (SOA not at apex)
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'subdomain.example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400']
        ]);
        $nsStmt = $this->createStatementMock([['name' => 'example.com']]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertGreaterThanOrEqual(1, count($result['issues']));

        $soaIssue = null;
        foreach ($result['issues'] as $issue) {
            if ($issue['type'] === 'soa_not_at_apex') {
                $soaIssue = $issue;
                break;
            }
        }

        $this->assertNotNull($soaIssue);
        $this->assertEquals('error', $soaIssue['severity']);
    }

    /**
     * Test invalid SOA content detection
     */
    public function testInvalidSoaContentDetected(): void
    {
        // Mock SOA record query (invalid content)
        $soaStmt = $this->createStatementMock([
            ['id' => 1, 'name' => 'example.com', 'content' => 'invalid soa content']
        ]);
        $nsStmt = $this->createStatementMock([['name' => 'example.com']]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertGreaterThanOrEqual(1, count($result['issues']));

        $soaIssue = null;
        foreach ($result['issues'] as $issue) {
            if ($issue['type'] === 'invalid_soa_content') {
                $soaIssue = $issue;
                break;
            }
        }

        $this->assertNotNull($soaIssue);
        $this->assertEquals('error', $soaIssue['severity']);
    }

    /**
     * Test that disabled SOA records are ignored
     */
    public function testDisabledSoaRecordsIgnored(): void
    {
        // Mock SOA record query - returns empty because disabled=0 filter excludes the disabled SOA
        // (simulating a zone where only disabled SOA exists)
        $soaStmt = $this->createStatementMock([]);
        $nsStmt = $this->createStatementMock([['name' => 'example.com']]);

        $this->dbMock->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('missing_soa', $result['issues'][0]['type']);
        $this->assertEquals('critical', $result['issues'][0]['severity']);
        $this->assertStringContainsString('No SOA record present, or active', $result['issues'][0]['message']);
    }
}
