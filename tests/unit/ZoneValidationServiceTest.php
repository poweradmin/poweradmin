<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\ZoneValidationService;

/**
 * Tests for ZoneValidationService
 *
 * Tests pre-flight zone validation checks before DNSSEC signing operations.
 */
class ZoneValidationServiceTest extends TestCase
{
    private $recordRepoMock;
    private ZoneValidationService $validator;

    protected function setUp(): void
    {
        $this->recordRepoMock = $this->createMock(RecordRepositoryInterface::class);
        $this->validator = new ZoneValidationService($this->recordRepoMock);
    }

    /**
     * Test validating a zone with valid configuration
     */
    public function testValidZonePassesValidation(): void
    {
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 0]];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0], ['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['issues']);
    }

    /**
     * Test missing SOA record detection
     */
    public function testMissingSoaRecordDetected(): void
    {
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [
                        ['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 0],
                        ['id' => 2, 'name' => 'example.com', 'content' => 'ns2.example.com hostmaster.example.com 2024010101 28800 7200 604800 86400', 'disabled' => 0],
                    ];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 0]];
                }
                if ($type === 'NS') {
                    return [];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 0]];
                }
                if ($type === 'NS') {
                    return [['name' => 'child.example.com', 'disabled' => 0], ['name' => 'sub.example.com', 'disabled' => 0]];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [['id' => 1, 'name' => 'subdomain.example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 0]];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    return [['id' => 1, 'name' => 'example.com', 'content' => 'invalid soa content', 'disabled' => 0]];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

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
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'SOA') {
                    // Only disabled SOA records
                    return [['id' => 1, 'name' => 'example.com', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 28800 7200 604800 86400', 'disabled' => 1]];
                }
                if ($type === 'NS') {
                    return [['name' => 'example.com', 'disabled' => 0]];
                }
                return [];
            });

        $result = $this->validator->validateZoneForDnssec(1, 'example.com');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('missing_soa', $result['issues'][0]['type']);
        $this->assertEquals('critical', $result['issues'][0]['severity']);
        $this->assertStringContainsString('No SOA record present, or active', $result['issues'][0]['message']);
    }
}
