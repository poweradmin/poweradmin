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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Zone ownership changes propagate to the PowerDNS account field (Issue #1358).
 * The account follows the oldest remaining direct owner, so a zone kept by a
 * second owner does not lose its account when the first one is removed.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryAccountSyncTest extends SqliteIntegrationTestCase
{
    private const ZONE = 42;
    private const ALICE = 10;
    private const BOB = 11;

    private DnsBackendProviderInterface&MockObject $backendProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES
            (" . self::ALICE . ", 'alice', 1), (" . self::BOB . ", 'bob', 1)");

        $this->backendProvider = $this->createMock(DnsBackendProviderInterface::class);
        $this->backendProvider->method('allocatesZoneIdsLocally')->willReturn(false);
    }

    private function repository(bool $syncEnabled, bool $withProvider = true): DbZoneRepository
    {
        return new DbZoneRepository(
            $this->db,
            $this->syncConfiguration($syncEnabled),
            $withProvider ? $this->backendProvider : null
        );
    }

    private function syncConfiguration(bool $syncEnabled): ConfigurationInterface
    {
        return $this->sqliteConfiguration(['dns' => ['sync_zone_owner_to_account' => $syncEnabled]]);
    }

    /** @return list<array{domain_id: int, owner: int, zone_templ_id: int}> */
    private function zoneRows(): array
    {
        $rows = $this->db->query("SELECT domain_id, owner, zone_templ_id FROM zones ORDER BY id")->fetchAll();

        return array_map(
            fn(array $row): array => [
                'domain_id' => (int)$row['domain_id'],
                'owner' => (int)$row['owner'],
                'zone_templ_id' => (int)$row['zone_templ_id'],
            ],
            $rows
        );
    }

    #[Test]
    public function addingAnOwnerWritesTheRowAndPushesTheAccount(): void
    {
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(self::ZONE, 'alice')
            ->willReturn(true);

        $this->assertTrue($this->repository(true)->addOwnerToZone(self::ZONE, self::ALICE));
        $this->assertSame(
            [['domain_id' => self::ZONE, 'owner' => self::ALICE, 'zone_templ_id' => 0]],
            $this->zoneRows()
        );
    }

    #[Test]
    public function anAddedOwnerInheritsTheTemplateOfTheExistingRow(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . self::ZONE . ", " . self::ALICE . ", 3)");
        $this->backendProvider->method('updateZoneAccount')->willReturn(true);

        $this->assertTrue($this->repository(true)->addOwnerToZone(self::ZONE, self::BOB));
        $this->assertSame(
            [
                ['domain_id' => self::ZONE, 'owner' => self::ALICE, 'zone_templ_id' => 3],
                ['domain_id' => self::ZONE, 'owner' => self::BOB, 'zone_templ_id' => 3],
            ],
            $this->zoneRows()
        );
    }

    #[Test]
    public function removingAnOwnerDeletesTheRowAndPushesTheRemainingOwner(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES
            (" . self::ZONE . ", " . self::ALICE . "), (" . self::ZONE . ", " . self::BOB . ")");

        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(self::ZONE, 'bob')
            ->willReturn(true);

        $this->assertTrue($this->repository(true)->removeOwnerFromZone(self::ZONE, self::ALICE));
        $this->assertSame(
            [['domain_id' => self::ZONE, 'owner' => self::BOB, 'zone_templ_id' => 0]],
            $this->zoneRows()
        );
    }

    #[Test]
    public function removingAnOwnerThatDoesNotOwnTheZoneChangesNothing(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::ZONE . ", " . self::ALICE . ")");
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $this->assertFalse($this->repository(true)->removeOwnerFromZone(self::ZONE, self::BOB));
        $this->assertSame(
            [['domain_id' => self::ZONE, 'owner' => self::ALICE, 'zone_templ_id' => 0]],
            $this->zoneRows()
        );
    }

    #[Test]
    public function theAccountIsLeftAloneWhenTheSettingIsOff(): void
    {
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $this->assertTrue($this->repository(false)->addOwnerToZone(self::ZONE, self::ALICE));
        $this->assertCount(1, $this->zoneRows());
    }

    #[Test]
    public function aMissingBackendProviderDoesNotBreakTheOwnerWrite(): void
    {
        $this->assertTrue($this->repository(true, false)->addOwnerToZone(self::ZONE, self::ALICE));
        $this->assertCount(1, $this->zoneRows());
    }
}
