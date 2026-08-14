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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\CatalogZoneService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\PermissionService;

#[CoversClass(CatalogZoneService::class)]
class CatalogZoneServiceTest extends TestCase
{
    private DnsBackendProvider&MockObject $backend;
    private PermissionService&MockObject $permissions;
    private CatalogZoneService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backend = $this->createMock(DnsBackendProvider::class);
        $this->permissions = $this->createMock(PermissionService::class);
        $this->service = new CatalogZoneService($this->backend, $this->permissions);
    }

    private function withMetaEditLevel(string $level, bool $ownsZone = false): void
    {
        $this->permissions->method('getZoneMetaEditPermissionLevel')->willReturn($level);
        $this->permissions->method('userOwnsZone')->willReturn($ownsZone);
    }

    private function producers(array $rows): void
    {
        $this->backend->method('getZonesByKind')->willReturnCallback(
            fn(string $kind): array => $kind === 'PRODUCER' ? $rows : []
        );
    }

    // ---- normalisation ----

    #[DataProvider('nameProvider')]
    #[Test]
    public function normalizeNameMatchesWhatPowerdnsStores(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->service->normalizeName($input));
    }

    public static function nameProvider(): array
    {
        return [
            'trailing dot' => ['producer.example.com.', 'producer.example.com'],
            'mixed case' => ['Producer.Example.COM', 'producer.example.com'],
            'both' => ['Producer.Example.COM.', 'producer.example.com'],
            'already canonical' => ['producer.example.com', 'producer.example.com'],
            'root' => ['.', ''],
            'empty' => ['', ''],
        ];
    }

    // ---- permission rule ----

    #[Test]
    public function metaEditOthersCanManageAnyZone(): void
    {
        $this->withMetaEditLevel('all');

        $this->assertTrue($this->service->canManageZone(1, 99));
    }

    #[Test]
    public function metaEditOwnCanManageOnlyOwnedZones(): void
    {
        $this->withMetaEditLevel('own', true);

        $this->assertTrue($this->service->canManageZone(1, 99));
    }

    #[Test]
    public function metaEditOwnCannotManageOthersZones(): void
    {
        $this->withMetaEditLevel('own', false);

        $this->assertFalse($this->service->canManageZone(1, 99));
    }

    #[Test]
    public function noMetaEditCannotManageAnything(): void
    {
        $this->withMetaEditLevel('none', true);

        $this->assertFalse($this->service->canManageZone(1, 99));
    }

    // ---- assign ----

    #[Test]
    public function assignWritesTheProducerNameResolvedFromItsId(): void
    {
        $this->withMetaEditLevel('all');
        $this->producers([['id' => 5, 'name' => 'producer.example.com', 'catalog' => '']]);

        $this->backend->expects($this->once())
            ->method('updateZoneCatalog')
            ->with(7, 'producer.example.com')
            ->willReturn(true);

        $this->assertTrue($this->service->assign(1, 7, 5));
    }

    #[Test]
    public function assignRefusesAZoneIdThatIsNotAProducer(): void
    {
        $this->withMetaEditLevel('all');
        $this->producers([['id' => 5, 'name' => 'producer.example.com', 'catalog' => '']]);

        $this->backend->expects($this->never())->method('updateZoneCatalog');

        $this->assertFalse($this->service->assign(1, 7, 404));
    }

    #[Test]
    public function assignRefusesWhenTheCallerCannotEditTheProducer(): void
    {
        // Rights on the member alone would let its owner inject the zone into a
        // catalog belonging to someone else.
        $this->permissions->method('getZoneMetaEditPermissionLevel')->willReturn('own');
        $this->permissions->method('userOwnsZone')->willReturnCallback(
            fn(int $userId, int $zoneId): bool => $zoneId === 7
        );
        $this->producers([['id' => 5, 'name' => 'producer.example.com', 'catalog' => '']]);

        $this->backend->expects($this->never())->method('updateZoneCatalog');

        $this->assertFalse($this->service->assign(1, 7, 5));
    }

    // ---- clear ----

    #[Test]
    public function clearResolvesTheProducerFromTheStoredValue(): void
    {
        $this->withMetaEditLevel('all');
        $this->producers([['id' => 5, 'name' => 'producer.example.com', 'catalog' => '']]);
        $this->backend->method('getZoneCatalog')->willReturn('producer.example.com');

        $this->backend->expects($this->once())
            ->method('updateZoneCatalog')
            ->with(7, '')
            ->willReturn(true);

        $this->assertTrue($this->service->clear(1, 7));
    }

    #[Test]
    public function clearIsANoOpForAZoneWithNoCatalog(): void
    {
        $this->withMetaEditLevel('all');
        $this->backend->method('getZoneCatalog')->willReturn('');

        $this->backend->expects($this->never())->method('updateZoneCatalog');

        $this->assertTrue($this->service->clear(1, 7));
    }

    #[Test]
    public function clearRefusesWhenTheCallerCannotEditTheCurrentProducer(): void
    {
        $this->permissions->method('getZoneMetaEditPermissionLevel')->willReturn('own');
        $this->permissions->method('userOwnsZone')->willReturnCallback(
            fn(int $userId, int $zoneId): bool => $zoneId === 7
        );
        $this->producers([['id' => 5, 'name' => 'producer.example.com', 'catalog' => '']]);
        $this->backend->method('getZoneCatalog')->willReturn('producer.example.com');

        $this->backend->expects($this->never())->method('updateZoneCatalog');

        $this->assertFalse($this->service->clear(1, 7));
    }

    #[Test]
    public function clearOfAnUnknownProducerNeedsEditRightsOnEveryZone(): void
    {
        $this->permissions->method('getZoneMetaEditPermissionLevel')->willReturn('own');
        $this->permissions->method('userOwnsZone')->willReturn(true);
        $this->producers([]);
        $this->backend->method('getZoneCatalog')->willReturn('gone.example.com');

        $this->backend->expects($this->never())->method('updateZoneCatalog');

        $this->assertFalse($this->service->clear(1, 7));
    }

    // ---- listings ----

    #[Test]
    public function eligibleMembersExcludeZonesAlreadyInACatalog(): void
    {
        $this->backend->method('getZonesByKind')->willReturnCallback(fn(string $kind): array => match ($kind) {
            'MASTER' => [
                ['id' => 1, 'name' => 'free.example.com', 'catalog' => ''],
                ['id' => 2, 'name' => 'taken.example.com', 'catalog' => 'producer.example.com'],
            ],
            'PRODUCER' => [['id' => 3, 'name' => 'nested.example.com', 'catalog' => '']],
            default => [],
        });

        $names = array_column($this->service->getEligibleMembers(), 'name');

        // NATIVE and SLAVE are never offered: PowerDNS would accept them and never publish.
        $this->assertSame(['free.example.com', 'nested.example.com'], $names);
    }

    #[Test]
    public function anEmptyProducerNameSelectsNoMembers(): void
    {
        // Guarded here so the two backends cannot disagree about what an empty
        // catalog name matches.
        $this->backend->expects($this->never())->method('getCatalogMembers');

        $this->assertSame([], $this->service->getMembers(''));
    }

    #[Test]
    public function membersAreFlaggedWithWhetherPowerdnsPublishesThem(): void
    {
        $this->backend->method('getCatalogMembers')->willReturn([
            ['id' => 1, 'name' => 'primary.example.com', 'kind' => 'MASTER'],
            ['id' => 2, 'name' => 'nested.example.com', 'kind' => 'PRODUCER'],
            ['id' => 3, 'name' => 'native.example.com', 'kind' => 'NATIVE'],
        ]);

        $members = $this->service->getMembers('Producer.Example.COM.');

        $this->assertTrue($members[0]['is_published']);
        $this->assertTrue($members[1]['is_published']);
        $this->assertFalse($members[2]['is_published'], 'NATIVE members are stored but never served');
    }
}
