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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\ZoneMetadataService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\BuildsPermissionService;

/**
 * The metadata rules the editor and the API share: vocabulary and single-value
 * kinds, companions checked against the resulting set, operator-only and
 * backend-refused kinds gated only when they change, and one persistence per
 * backend.
 */
#[CoversClass(ZoneMetadataService::class)]
class ZoneMetadataServiceTest extends TestCase
{
    use BuildsPermissionService;

    private const ZONE_ID = 42;
    private const ZONE = 'example.com';
    private const ADMIN = 1;
    private const EDITOR = 7;

    private ZoneRepositoryInterface&MockObject $zoneRepository;
    private ConfigurationInterface&MockObject $config;
    private AuditService&MockObject $audit;
    private RecordChangeLogger&MockObject $changeLogger;

    protected function setUp(): void
    {
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
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
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);

        $result = $service->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'SOA-EDIT-API', 'content' => 'BOGUS']], self::ADMIN);

        $this->assertSame(ZoneMetadataOutcome::INVALID_VALUE, $result->outcome);
        $this->assertSame('SOA-EDIT-API', $result->kind);
        $this->assertContains('INCREASE', $result->detail['options']);
    }

    public function testConfiguredVocabularyNarrowsAcceptedValues(): void
    {
        $this->config = $this->createMock(ConfigurationInterface::class);
        $this->config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $group === 'dns' && $key === 'soa_edit_api_options' ? ['EPOCH'] : $default
        );
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);
        $this->zoneRepository->method('replaceDomainMetadata')->willReturn(true);
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
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);
        $this->zoneRepository->method('replaceDomainMetadata')->willReturn(true);
        $rows = [['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'], ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.11']];

        $this->assertTrue($this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, $rows, self::ADMIN)->isOk());
    }

    public function testNsec3NarrowNeedsNsec3ParamInTheResultingSet(): void
    {
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);
        $service = $this->sqlService();

        $alone = $service->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'NSEC3NARROW', 'content' => '1']], self::ADMIN);
        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $alone->outcome);
        $this->assertSame('NSEC3PARAM', $alone->detail['companion']);

        // Per kind, the companion may already be stored
        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $service->replaceKind(self::ZONE_ID, self::ZONE, 'NSEC3NARROW', ['1'], self::ADMIN)->outcome);
    }

    public function testRemovingTheCompanionAnotherKindNeedsIsRefused(): void
    {
        $this->zoneRepository->method('getDomainMetadata')->willReturn([
            ['kind' => 'NSEC3PARAM', 'content' => '1 0 1 ab'],
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
        ]);
        $this->zoneRepository->expects($this->never())->method('replaceDomainMetadata');

        $result = $this->sqlService()->deleteKind(self::ZONE_ID, self::ZONE, 'NSEC3PARAM', self::ADMIN);

        $this->assertSame(ZoneMetadataOutcome::COMPANION_REQUIRED, $result->outcome);
        $this->assertSame('NSEC3NARROW', $result->kind);
    }

    public function testOperatorOnlyKindsAreGatedOnlyWhenTheyChange(): void
    {
        $this->zoneRepository->method('getDomainMetadata')->willReturn([['kind' => 'LUA-AXFR-SCRIPT', 'content' => 'admin.lua']]);
        $this->zoneRepository->method('replaceDomainMetadata')->willReturn(true);
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
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);

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
        $this->assertNull($this->sqlService()->writeRejection('PRESIGNED'));
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

    public function testReplaceKindOnSqlRewritesOnlyThatKind(): void
    {
        $this->zoneRepository->method('getDomainMetadata')->willReturn([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'X-NOTE', 'content' => 'old'],
        ]);
        $this->zoneRepository->expects($this->once())->method('replaceDomainMetadata')
            ->with(self::ZONE_ID, [['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'], ['kind' => 'X-NOTE', 'content' => 'new']])
            ->willReturn(true);

        $this->assertTrue($this->sqlService()->replaceKind(self::ZONE_ID, self::ZONE, 'x-note', ['new'], self::ADMIN)->isOk());
    }

    public function testAFailedWriteIsReportedAndNotLogged(): void
    {
        $this->zoneRepository->method('getDomainMetadata')->willReturn([]);
        $this->zoneRepository->method('replaceDomainMetadata')->willReturn(false);
        $this->audit->expects($this->never())->method('logZoneMetadataEdit');

        $this->assertSame(ZoneMetadataOutcome::WRITE_FAILED, $this->sqlService()->replaceAll(self::ZONE_ID, self::ZONE, [['kind' => 'X-NOTE', 'content' => '1']], self::ADMIN)->outcome);
    }

    public function testKindSupportFollowsTheServerVersionOnlyOnTheApiBackend(): void
    {
        $gated = ['min_version' => '4.8.0'];
        $api = $this->apiService($this->createMock(PowerdnsApiClient::class));

        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $this->sqlService()->kindSupport($gated, PdnsCapabilities::fromVersion(null)));
        $this->assertSame(ZoneMetadataService::SUPPORT_UNKNOWN, $api->kindSupport($gated, PdnsCapabilities::fromVersion(null)));
        $this->assertSame(ZoneMetadataService::SUPPORT_UNSUPPORTED_KNOWN, $api->kindSupport($gated, PdnsCapabilities::fromVersion('4.7.0')));
        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $api->kindSupport($gated, PdnsCapabilities::fromVersion('4.8.3')));
        $this->assertSame(ZoneMetadataService::SUPPORT_SUPPORTED, $api->kindSupport([], PdnsCapabilities::fromVersion(null)));
    }

    private function sqlService(): ZoneMetadataService
    {
        return $this->service(null);
    }

    private function apiService(PowerdnsApiClient $apiClient): ZoneMetadataService
    {
        return $this->service($apiClient);
    }

    private function service(?PowerdnsApiClient $apiClient): ZoneMetadataService
    {
        return new ZoneMetadataService(
            $this->zoneRepository,
            $this->config,
            $this->buildPermissionService(adminUserIds: [self::ADMIN]),
            $this->audit,
            $this->changeLogger,
            $apiClient
        );
    }
}
