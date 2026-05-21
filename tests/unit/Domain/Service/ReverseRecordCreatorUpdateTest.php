<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use PDO;
use PDOStatement;

class ReverseRecordCreatorUpdateTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>>|null $deleteLookupRows Rows returned by the
     *        PTR-lookup SELECT inside deleteReverseRecord. Null means "no PDO mock" -
     *        used when the test does not expect deleteReverseRecord to run.
     */
    private function createService(?DnsRecord $dnsRecord = null, ?array $deleteLookupRows = null): ReverseRecordCreator
    {
        $db = $this->createMock(PDO::class);
        if ($deleteLookupRows !== null) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn($deleteLookupRows[0] ?? false);
            $db->method('prepare')->willReturn($stmt);
        }

        $logger = $this->createMock(LegacyLogger::class);
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_reverse_record') {
                return true;
            }
            return $default;
        });

        if ($dnsRecord === null) {
            $dnsRecord = $this->createMock(DnsRecord::class);
        }

        return new ReverseRecordCreator($db, $config, $logger, $dnsRecord);
    }

    public function testUpdateReverseRecordSkipsWhenBothTypesAreNonAddress(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($this->never())->method('getBestMatchingZoneIdFromName');
        $dnsRecord->expects($this->never())->method('addRecord');
        $dnsRecord->expects($this->never())->method('deleteRecord');

        $service = $this->createService($dnsRecord);

        $result = $service->updateReverseRecord(
            'CNAME',
            'old.example.com',
            'alias.example.com',
            'CNAME',
            'new.example.com',
            'alias.example.com',
            1,
            3600,
            0
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('not A or AAAA', $result['message']);
    }

    public function testUpdateReverseRecordReSyncsPtrEvenWhenAddressUnchanged(): void
    {
        // TTL/priority-only edits must propagate to the PTR, so the service
        // always runs delete-then-recreate when newIsAddress is true.
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($this->atLeastOnce())->method('getBestMatchingZoneIdFromName')->willReturn(-1);
        $dnsRecord->expects($this->never())->method('addRecord');

        $service = $this->createService($dnsRecord, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'A',
            '192.0.2.10',
            'host.example.com',
            1,
            7200,
            0
        );

        // With no reverse zone in the mock, createReverseRecord returns an error.
        // The point of the assertion is that the delete-then-create path RAN
        // rather than being short-circuited.
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no matching reverse-zone', $result['message']);
    }

    public function testUpdateReverseRecordReportsMissingReverseZoneOnNewContent(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        // Simulate "no matching reverse zone" for the new IP.
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(-1);
        $dnsRecord->expects($this->never())->method('addRecord');

        // Old PTR lookup returns no rows - that's fine, delete is best-effort.
        $service = $this->createService($dnsRecord, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'A',
            '198.51.100.20',
            'host.example.com',
            1,
            3600,
            0
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no matching reverse-zone', $result['message']);
    }

    public function testUpdateReverseRecordOnlyDeletesWhenNewTypeIsNotAddress(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($this->never())->method('getBestMatchingZoneIdFromName');
        $dnsRecord->expects($this->never())->method('addRecord');

        // Old PTR lookup returns no rows - delete is best-effort.
        $service = $this->createService($dnsRecord, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'CNAME',
            'target.example.com',
            'host.example.com',
            1,
            3600,
            0
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('removed', strtolower($result['message']));
    }
}
