<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Zone\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use PDO;
use Poweradmin\Infrastructure\Repository\ApiZoneMetadataStore;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\PermissionServiceTestCase;

/**
 * The metadata rules the editor and the API share: vocabulary and single-value
 * kinds, companions checked against the resulting set, operator-only and
 * backend-refused kinds gated only when they change, and one persistence per
 * backend.
 */
#[CoversClass(ZoneMetadataService::class)]
class ZoneMetadataServiceTest extends PermissionServiceTestCase
{

    private const ZONE_ID = 42;
    private const ZONE = 'example.com';
    private const ADMIN = 1;
    private const EDITOR = 7;

    private ZoneMetadataStoreInterface&MockObject $store;
    private ConfigurationInterface&MockObject $config;
    private AuditService&MockObject $audit;
    private RecordChangeLogger&MockObject $changeLogger;

    protected function setUp(): void
    {
        $this->store = $this->createMock(ZoneMetadataStoreInterface::class);
        $this->config = $this->createMock(ConfigurationInterface::class);
        $this->config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);
        $this->audit = $this->createMock(AuditService::class);
        $this->changeLogger = $this->createMock(RecordChangeLogger::class);
    }

    public function testNormalizeRowsDropsBlankRowsAndUppercasesKinds(): void
    {
        $rows = ZoneMetadataService::normalizeRows([
            ['kind' => ' allow-axfr-from ', 'content' => ' 192.0.2.10 '],
            ['kind' => 'X-EMPTY', 'content' => '  '],
            ['kind' => '', 'content' => 'orphan'],
        ]);

        $this->assertSame([['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10']], $rows);
    }

    public function testChangedKindsIgnoresReorderedValues(): void
    {
        $before = [['kind' => 'ALLOW-AXFR-FROM', 'content' => 'a'], ['kind' => 'ALLOW-AXFR-FROM', 'content' => 'b'], ['kind' => 'X-OLD', 'content' => '1']];
        $after = [['kind' => 'ALLOW-AXFR-FROM', 'content' => 'b'], ['kind' => 'ALLOW-AXFR-FROM', 'content' => 'a'], ['kind' => 'X-NEW', 'content' => '1']];

        $this->assertSame(['X-OLD', 'X-NEW'], ZoneMetadataService::changedKinds($after, $before));
    }

    public function testValuesOutsideAKindVocabularyAreRefused(): void
    {
        $service = $this->sqlService();
        $this->store->method('load')->willReturn([]);

        $result = $service->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'SOA-EDIT-API', 'content' => 'BOGUS']], self::ADMIN);

        $this->assertSame(ZoneMetadataOutcome::INVALID_VALUE, $result->outcome);
        $this->assertSame('SOA-EDIT-API', $result->kind);
        $this->assertContains('INCREASE', $result->options);
    }

    public function testConfiguredVocabularyNarrowsAcceptedValues(): void
    {
        $this->config = $this->createMock(ConfigurationInterface::class);
        $this->config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $group === 'dns' && $key === 'soa_edit_api_options' ? ['EPOCH'] : $default
        );
        $this->store->method('load')->willReturn([]);
        $this->store->method('replaceKind')->willReturn(true);
        $service = $this->sqlService();

        $this->assertSame(ZoneMetadataOutcome::INVALID_VALUE, $service->replaceKind(self::ZONE_ID, self::ZONE, 'SOA-EDIT-API', ['INCREASE'], self::ADMIN)->outcome);
        $this->assertTrue($service->replaceKind(self::ZONE_ID, self::ZONE, 'SOA-EDIT-API', ['EPOCH'], self::ADMIN)->isOk());
    }

    public function testASingleValueKindRefusesASecondRow(): void
    {
        $rows = [['kind' => 'SOA-EDIT', 'content' => 'INCEPTION-INCREMENT'], ['kind' => 'SOA-EDIT', 'content' => 'EPOCH']];

        $this->assertSame(ZoneMetadataOutcome::SINGLE_VALUE_ONLY, $this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, $rows, self::ADMIN)->outcome);
    }

    public function testRepeatedMultiValueKindsAreAccepted(): void
    {
        $this->store->method('load')->willReturn([]);
        $this->store->method('replaceAll')->willReturn(true);
        $rows = [['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'], ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.11']];

        $this->assertTrue($this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, $rows, self::ADMIN)->isOk());
    }

    public function testNsec3NarrowNeedsNsec3ParamInTheResultingSet(): void
    {
        $this->store->method('load')->willReturn([]);
        $service = $this->sqlService();

        $alone = $service->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'NSEC3NARROW', 'content' => '1']], self::ADMIN);
        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $alone->outcome);
        $this->assertSame('NSEC3PARAM', $alone->companion);

        // Per kind, the companion may already be stored
        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $service->replaceKind(self::ZONE_ID, self::ZONE, 'NSEC3NARROW', ['1'], self::ADMIN)->outcome);
    }

    public function testRemovingTheCompanionAnotherKindNeedsIsRefused(): void
    {
        $this->store->method('load')->willReturn([
            ['kind' => 'NSEC3PARAM', 'content' => '1 0 1 ab'],
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
        ]);
        $this->store->expects($this->never())->method('replaceKind');

        $result = $this->sqlService()->deleteKind(self::ZONE_ID, self::ZONE, 'NSEC3PARAM', self::ADMIN);

        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $result->outcome);
        $this->assertSame('NSEC3NARROW', $result->kind);
    }

    public function testAnUnrelatedDeleteIgnoresAnAlreadyInconsistentSet(): void
    {
        $this->store->method('load')->willReturn([
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
            ['kind' => 'IXFR', 'content' => '1'],
        ]);
        $this->store->method('replaceKind')->willReturn(true);

        $this->assertTrue($this->sqlService()->deleteKind(self::ZONE_ID, self::ZONE, 'IXFR', self::ADMIN)->isOk());
    }

    public function testOperatorOnlyKindsAreGatedOnlyWhenTheyChange(): void
    {
        $this->store->method('load')->willReturn([['kind' => 'LUA-AXFR-SCRIPT', 'content' => 'admin.lua']]);
        $this->store->method('replaceAll')->willReturn(true);
        $service = $this->sqlService();

        // An administrator-set row echoed back does not lock the zone's editor out
        $unchanged = [['kind' => 'LUA-AXFR-SCRIPT', 'content' => 'admin.lua'], ['kind' => 'X-NOTE', 'content' => 'x']];
        $this->assertTrue($service->replaceAll(self::ZONE_ID, self::ZONE, $unchanged, self::EDITOR)->isOk());

        $changed = [['kind' => 'LUA-AXFR-SCRIPT', 'content' => 'evil.lua']];
        $this->assertSame(ZoneMetadataOutcome::OPERATOR_ONLY, $service->replaceAll(self::ZONE_ID, self::ZONE, $changed, self::EDITOR)->outcome);
        $this->assertSame(ZoneMetadataOutcome::OPERATOR_ONLY, $service->deleteKind(self::ZONE_ID, self::ZONE, 'LUA-AXFR-SCRIPT', self::EDITOR)->outcome);
        $this->assertTrue($service->replaceAll(self::ZONE_ID, self::ZONE, $changed, self::ADMIN)->isOk());
    }

    public function testServerManagedKindsAreRefusedOnEveryBackend(): void
    {
        $this->store->method('load')->willReturn([]);

        $result = $this->sqlService()->replaceKind(self::ZONE_ID, self::ZONE, 'CATALOG-HASH', ['x'], self::ADMIN);

        $this->assertSame(ZoneMetadataOutcome::SERVER_MANAGED, $result->outcome);
    }

    public function testTheApiBackendRefusesWhatPowerDnsCannotStore(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        $apiClient->method('getZone')->willReturn([]);
        $service = $this->apiService($apiClient);

        $this->assertSame(ZoneMetadataOutcome::CUSTOM_PREFIX, $service->replaceKind(self::ZONE_ID, self::ZONE, 'MY-KIND', ['1'], self::ADMIN)->outcome);
        $this->assertSame(ZoneMetadataOutcome::NO_API_ROUTE, $service->replaceKind(self::ZONE_ID, self::ZONE, 'PRESIGNED', ['1'], self::ADMIN)->outcome);
        $this->assertSame(MetadataDefinitions::REJECT_NO_API_ROUTE, $service->writeRejection('PRESIGNED'));
        $this->assertNull($this->dbService()->writeRejection('PRESIGNED'));
    }

    public function testAnOverlongKindIsRefusedNotTruncated(): void
    {
        $kind = str_repeat('X', ZoneMetadataService::MAX_KIND_LENGTH + 1);

        $this->assertSame(ZoneMetadataOutcome::INVALID_KIND, $this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => $kind, 'content' => '1']], self::ADMIN)->outcome);
    }

    public function testEmptyValuesAreRefusedPerKind(): void
    {
        $this->assertSame(ZoneMetadataOutcome::EMPTY_VALUES, $this->sqlService()->replaceKind(self::ZONE_ID, self::ZONE, 'X-NOTE', [' ', ''], self::ADMIN)->outcome);
    }

    public function testLoadFoldsZoneObjectKindsInOnceWithTheAbsoluteName(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->expects($this->once())->method('getZoneMetadata')
            ->with($this->callback(fn(Zone $zone): bool => $zone->getName() === 'example.com.'))
            ->willReturn([
                ['kind' => 'SOA-EDIT-API', 'metadata' => ['DEFAULT']],
                ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']],
            ]);
        $apiClient->expects($this->once())->method('getZone')->with('example.com.', false)->willReturn(['soa_edit_api' => 'INCREASE', 'soa_edit' => 'EPOCH']);

        $rows = $this->apiService($apiClient)->load(self::ZONE_ID, self::ZONE);

        $this->assertSame([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'SOA-EDIT', 'content' => 'EPOCH'],
            ['kind' => 'SOA-EDIT-API', 'content' => 'INCREASE'],
        ], $rows);
    }

    public function testReplaceAllRoutesZoneObjectKindsThroughOneZoneUpdateAndClearsRemovedOnes(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']]]);
        $apiClient->method('getZone')->willReturn(['soa_edit' => 'EPOCH', 'api_rectify' => true]);
        $apiClient->expects($this->once())->method('updateZoneProperties')
            ->with('example.com.', ['soa_edit_api' => 'INCREASE', 'soa_edit' => '', 'api_rectify' => false])
            ->willReturn(true);
        $apiClient->expects($this->once())->method('updateZoneMetadata')
            ->with($this->anything(), 'X-NOTE', ['hello'])
            ->willReturn(true);
        $apiClient->expects($this->once())->method('deleteZoneMetadata')->with($this->anything(), 'ALLOW-AXFR-FROM')->willReturn(true);
        $this->audit->expects($this->once())->method('logZoneMetadataEdit')->with(self::ZONE_ID, self::ZONE, ['SOA-EDIT-API', 'X-NOTE']);

        $rows = [['kind' => 'SOA-EDIT-API', 'content' => 'INCREASE'], ['kind' => 'X-NOTE', 'content' => 'hello']];
        $this->assertTrue($this->apiService($apiClient)->replaceAll(self::ZONE_ID, self::ZONE, $rows, self::ADMIN)->isOk());
    }

    public function testDeleteKindClearsABooleanZoneObjectKindWithFalse(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        $apiClient->method('getZone')->willReturn(['api_rectify' => true]);
        $apiClient->expects($this->once())->method('updateZoneProperties')->with('example.com.', ['api_rectify' => false])->willReturn(true);
        $apiClient->expects($this->never())->method('deleteZoneMetadata');

        $this->assertTrue($this->apiService($apiClient)->deleteKind(self::ZONE_ID, self::ZONE, 'API-RECTIFY', self::ADMIN)->isOk());
    }

    public function testReplaceKindOnTheApiBackendWritesOnlyThatKindsMetadata(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']]]);
        $apiClient->method('getZone')->willReturn([]);
        $apiClient->expects($this->never())->method('updateZoneProperties');
        $apiClient->expects($this->never())->method('deleteZoneMetadata');
        $apiClient->expects($this->once())->method('updateZoneMetadata')
            ->with($this->callback(fn(Zone $zone): bool => $zone->getName() === 'example.com.'), 'X-NOTE', ['hello'])
            ->willReturn(true);

        $this->assertTrue($this->apiService($apiClient)->replaceKind(self::ZONE_ID, self::ZONE, 'x-note', ['hello'], self::ADMIN)->isOk());
    }

    public function testDeleteKindOnTheApiBackendRemovesOnlyThatKindsMetadata(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([['kind' => 'X-NOTE', 'metadata' => ['hello']]]);
        $apiClient->method('getZone')->willReturn([]);
        $apiClient->expects($this->never())->method('updateZoneMetadata');
        $apiClient->expects($this->once())->method('deleteZoneMetadata')
            ->with($this->callback(fn(Zone $zone): bool => $zone->getName() === 'example.com.'), 'X-NOTE')
            ->willReturn(true);

        $this->assertTrue($this->apiService($apiClient)->deleteKind(self::ZONE_ID, self::ZONE, 'X-NOTE', self::ADMIN)->isOk());
    }

    public function testAFailedApiWriteIsReportedAndNotLogged(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        $apiClient->method('getZone')->willReturn([]);
        $apiClient->method('updateZoneMetadata')->willReturn(false);
        $this->audit->expects($this->never())->method('logZoneMetadataEdit');

        $this->assertSame(ZoneMetadataOutcome::WRITE_FAILED, $this->apiService($apiClient)->replaceKind(self::ZONE_ID, self::ZONE, 'X-NOTE', ['1'], self::ADMIN)->outcome);
    }

    public function testReplaceKindHandsTheStoreTheKindAndTheSnapshot(): void
    {
        $this->store->method('load')->willReturn([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'X-NOTE', 'content' => 'old'],
        ]);
        $this->store->expects($this->once())->method('replaceKind')
            ->with(self::ZONE_ID, self::ZONE, 'X-NOTE', ['new'], [['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'], ['kind' => 'X-NOTE', 'content' => 'old']])
            ->willReturn(true);

        $this->assertTrue($this->sqlService()->replaceKind(self::ZONE_ID, self::ZONE, 'x-note', ['new'], self::ADMIN)->isOk());
    }

    public function testAFailedWriteIsReportedAndNotLogged(): void
    {
        $this->store->method('load')->willReturn([]);
        $this->store->method('replaceAll')->willReturn(false);
        $this->audit->expects($this->never())->method('logZoneMetadataEdit');

        $this->assertSame(ZoneMetadataOutcome::WRITE_FAILED, $this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'X-NOTE', 'content' => '1']], self::ADMIN)->outcome);
    }

    public function testKindSupportFollowsTheServerVersionOnlyOnTheApiBackend(): void
    {
        $gated = ['min_version' => '4.8.0'];
        $api = $this->apiService($this->createMock(PowerdnsApiClient::class));
        $version = fn(?string $version): callable => fn(): PdnsCapabilities => PdnsCapabilities::fromVersion($version);
        $neverAsked = fn(): PdnsCapabilities => $this->fail('the database store must not probe the server version');

        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $this->dbService()->kindSupport($gated, $neverAsked));
        $this->assertSame(ZoneMetadataService::SUPPORT_UNKNOWN, $api->kindSupport($gated, $version(null)));
        $this->assertSame(ZoneMetadataService::SUPPORT_UNSUPPORTED_KNOWN, $api->kindSupport($gated, $version('4.7.0')));
        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $api->kindSupport($gated, $version('4.8.3')));
        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $api->kindSupport([], $neverAsked));
    }

    /**
     * The database store over a mocked connection: its support answers need no rows.
     */
    private function dbService(): ZoneMetadataService
    {
        return $this->service(new DbZoneMetadataStore($this->createMock(PDO::class), $this->config), $this->config);
    }

    private function sqlService(): ZoneMetadataService
    {
        return $this->service($this->store, $this->config);
    }

    /**
     * The API backend: the real API store over a mocked client.
     */
    private function apiService(PowerdnsApiClient $apiClient): ZoneMetadataService
    {
        return $this->service(new ApiZoneMetadataStore($apiClient), $this->config);
    }

    private function service(ZoneMetadataStoreInterface $store, ConfigurationInterface $config): ZoneMetadataService
    {
        return new ZoneMetadataService(
            $store,
            $config,
            $this->buildPermissionService(adminUserIds: [self::ADMIN]),
            $this->audit,
            $this->changeLogger
        );
    }
}
