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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Infrastructure\Database\PDOCommon;

#[CoversClass(ZoneValidationService::class)]
class ZoneValidationServiceTest extends TestCase
{
    private ZoneValidationService $service;
    private PDOCommon&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDOCommon::class);
        $this->service = new ZoneValidationService($this->db);
    }

    // ========== getFormattedErrorMessage tests ==========

    #[Test]
    public function testGetFormattedErrorMessageReturnsEmptyForValidResult(): void
    {
        $result = [
            'valid' => true,
            'issues' => []
        ];

        $message = $this->service->getFormattedErrorMessage($result);
        $this->assertEquals('', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageFormatsAllCriticalErrors(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'missing_soa',
                    'severity' => 'critical',
                    'message' => 'No SOA record present',
                    'suggestion' => 'Add an SOA record'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        $this->assertStringContainsString('[CRITICAL]', $message);
        $this->assertStringContainsString('No SOA record present', $message);
        $this->assertStringContainsString('Add an SOA record', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageFormatsRegularErrors(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'soa_not_at_apex',
                    'severity' => 'error',
                    'message' => 'SOA record not at apex',
                    'suggestion' => 'Move the SOA record'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        $this->assertStringContainsString('[ERROR]', $message);
        $this->assertStringContainsString('SOA record not at apex', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageFormatsWarnings(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'warning_type',
                    'severity' => 'warning',
                    'message' => 'This is a warning',
                    'suggestion' => 'Consider fixing this'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        $this->assertStringContainsString('[WARNING]', $message);
        $this->assertStringContainsString('This is a warning', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageIncludesSuggestions(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'test',
                    'severity' => 'error',
                    'message' => 'Test error',
                    'suggestion' => 'Test suggestion'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        $this->assertStringContainsString('Test suggestion', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageHandlesIssueWithoutSuggestion(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'test',
                    'severity' => 'error',
                    'message' => 'Test error without suggestion'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        $this->assertStringContainsString('Test error without suggestion', $message);
    }

    #[Test]
    public function testGetFormattedErrorMessageSeparatesErrorsAndWarnings(): void
    {
        $result = [
            'valid' => false,
            'issues' => [
                [
                    'type' => 'critical_issue',
                    'severity' => 'critical',
                    'message' => 'Critical issue'
                ],
                [
                    'type' => 'error_issue',
                    'severity' => 'error',
                    'message' => 'Error issue'
                ],
                [
                    'type' => 'warning_issue',
                    'severity' => 'warning',
                    'message' => 'Warning issue'
                ]
            ]
        ];

        $message = $this->service->getFormattedErrorMessage($result);

        // Critical should come before error
        $criticalPos = strpos($message, 'Critical issue');
        $errorPos = strpos($message, 'Error issue');
        $warningPos = strpos($message, 'Warning issue');

        $this->assertLessThan($errorPos, $criticalPos);
        $this->assertLessThan($warningPos, $errorPos);
    }

    // ========== validateZoneForDnssec tests ==========

    #[Test]
    public function testValidateZoneForDnssecWithValidZone(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'name' => 'example.com',
                'content' => 'ns1.example.com. hostmaster.example.com. 2024010101 3600 600 86400 3600'
            ]
        ]);

        // Mock NS query
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([
            ['name' => 'example.com'],
            ['name' => 'example.com']
        ]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['issues']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithMissingSoa(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - no records
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([]);

        // Mock NS query
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([['name' => 'example.com']]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['issues']);
        $this->assertEquals('missing_soa', $result['issues'][0]['type']);
        $this->assertEquals('critical', $result['issues'][0]['severity']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithMultipleSoa(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - multiple records
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1 host 1 1 1 1 1'],
            ['id' => 2, 'name' => 'example.com', 'content' => 'ns2 host 2 2 2 2 2']
        ]);

        // Mock NS query
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([['name' => 'example.com']]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertEquals('multiple_soa', $result['issues'][0]['type']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithSoaNotAtApex(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - SOA at wrong name
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'sub.example.com', 'content' => 'ns1 host 1 1 1 1 1']
        ]);

        // Mock NS query
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([['name' => 'example.com']]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertEquals('soa_not_at_apex', $result['issues'][0]['type']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithInvalidSoaContent(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - invalid content format
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'content' => 'incomplete content']
        ]);

        // Mock NS query
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([['name' => 'example.com']]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertEquals('invalid_soa_content', $result['issues'][0]['type']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithMissingApexNs(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - valid
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1 host 1 1 1 1 1']
        ]);

        // Mock NS query - no apex NS records (only delegation)
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([
            ['name' => 'sub.example.com'] // This is a delegation, not apex
        ]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertEquals('missing_apex_ns', $result['issues'][0]['type']);
    }

    #[Test]
    public function testValidateZoneForDnssecWithNoNsRecords(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - valid
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1 host 1 1 1 1 1']
        ]);

        // Mock NS query - no records at all
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertFalse($result['valid']);
        $this->assertEquals('missing_apex_ns', $result['issues'][0]['type']);
    }

    #[Test]
    public function testValidateZoneForDnssecHandlesTrailingDots(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com.';

        // Mock SOA query - with trailing dot
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com.', 'content' => 'ns1 host 1 1 1 1 1']
        ]);

        // Mock NS query - with trailing dot
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([['name' => 'example.com.']]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function testValidateZoneForDnssecCountsMultipleApexNs(): void
    {
        $zoneId = 1;
        $zoneName = 'example.com';

        // Mock SOA query - valid
        $soaStmt = $this->createMock(PDOStatement::class);
        $soaStmt->method('execute')->willReturn(true);
        $soaStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'content' => 'ns1 host 1 1 1 1 1']
        ]);

        // Mock NS query - multiple apex NS + delegation
        $nsStmt = $this->createMock(PDOStatement::class);
        $nsStmt->method('execute')->willReturn(true);
        $nsStmt->method('fetchAll')->willReturn([
            ['name' => 'example.com'],
            ['name' => 'example.com'],
            ['name' => 'sub.example.com'] // delegation - should be ignored
        ]);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($soaStmt, $nsStmt);

        $result = $this->service->validateZoneForDnssec($zoneId, $zoneName);

        $this->assertTrue($result['valid']);
    }
}
