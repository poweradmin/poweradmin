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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Psr\Log\NullLogger;
use TestHelpers\SqliteIntegrationTestCase;
use TestHelpers\ZoneTemplateServiceBuilder;

/**
 * Characterizes the four zone-listing queries on the zone template service: which
 * zones a template lists for a user at each edit permission level, what shape the
 * rows have, and how the API backend swaps the PowerDNS tables for client calls.
 */
class ZoneTemplateServiceLinkedZonesTest extends SqliteIntegrationTestCase
{
    private const OWN_EDITOR = 2;
    private const OTHER_USER = 3;
    private const OWN_PERM_TEMPL = 2;
    private const EDITOR_GROUP = 5;
    private const TEMPLATE = 10;
    private const OTHER_TEMPLATE = 11;

    // Domain ids are kept apart from zones.id so a query reading the wrong id column fails.
    private const DIRECT_DOMAIN = 101;
    private const GROUP_DOMAIN = 102;
    private const FOREIGN_DOMAIN = 103;
    private const OTHER_TEMPLATE_DOMAIN = 104;
    private const ORPHAN_DOMAIN = 999;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (46, '" . Permission::PERM_ZONE_CONTENT_EDIT_OWN . "')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::OWN_PERM_TEMPL . ", 'Own editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::OWN_PERM_TEMPL . ", 46)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ, fullname) VALUES
            (" . self::OWN_EDITOR . ", 'editor', " . self::OWN_PERM_TEMPL . ", 'Own Editor'),
            (" . self::OTHER_USER . ", 'other', " . self::OWN_PERM_TEMPL . ", 'Other User')");
        $this->db->exec("UPDATE users SET fullname = 'Admin User' WHERE id = " . self::ADMIN_USER_ID);
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (" . self::EDITOR_GROUP . ", 'editors', NULL)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::OWN_EDITOR . ", " . self::EDITOR_GROUP . ")");

        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (" . self::TEMPLATE . ", 'shared', 0), (" . self::OTHER_TEMPLATE . ", 'other', 0)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (" . self::DIRECT_DOMAIN . ", 'direct.example', 'MASTER'),
            (" . self::GROUP_DOMAIN . ", 'group.example', 'NATIVE'),
            (" . self::FOREIGN_DOMAIN . ", 'foreign.example', 'MASTER'),
            (" . self::OTHER_TEMPLATE_DOMAIN . ", 'aaa-other.example', 'MASTER')");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content) VALUES
            (" . self::DIRECT_DOMAIN . ", 'direct.example', 'SOA', 'x'),
            (" . self::DIRECT_DOMAIN . ", 'www.direct.example', 'A', '192.0.2.1'),
            (" . self::FOREIGN_DOMAIN . ", 'foreign.example', 'SOA', 'x')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id) VALUES
            (1, " . self::DIRECT_DOMAIN . ", " . self::OWN_EDITOR . ", 'mine', " . self::TEMPLATE . "),
            (2, " . self::GROUP_DOMAIN . ", " . self::OTHER_USER . ", 'via group', " . self::TEMPLATE . "),
            (3, " . self::FOREIGN_DOMAIN . ", " . self::ADMIN_USER_ID . ", NULL, " . self::TEMPLATE . "),
            (4, " . self::OTHER_TEMPLATE_DOMAIN . ", " . self::OWN_EDITOR . ", '', " . self::OTHER_TEMPLATE . "),
            (5, " . self::ORPHAN_DOMAIN . ", " . self::OWN_EDITOR . ", 'stale', " . self::TEMPLATE . ")");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (" . self::GROUP_DOMAIN . ", " . self::EDITOR_GROUP . ")");
    }

    private function model(DnsBackendProviderInterface $backend): ZoneTemplateService
    {
        return ZoneTemplateServiceBuilder::build(new DbZoneTemplateRepository($this->db, $this->config, $backend), $this->config, $backend, $this->permissionService(), new SessionActor(), new NullLogger());
    }

    private function actingAs(int $userId): void
    {
        $_SESSION['userid'] = $userId;
    }

    /**
     * SQL-backend rows come back in the connection's default fetch mode, which the
     * application leaves at FETCH_BOTH; the named keys are what the templates read.
     *
     * @param array<int, array<int|string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function namedColumns(array $rows): array
    {
        return array_map(
            static fn(array $row): array => array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY),
            $rows
        );
    }

    /**
     * API backend whose zone details come from the client, never from a domains table.
     */
    private function apiBackend(): DnsBackendProviderInterface&MockObject
    {
        $backend = $this->dnsBackendStub(true);
        $backend->method('getZoneById')->willReturnCallback(fn(int $id): ?array => [
            self::DIRECT_DOMAIN => ['name' => 'direct.example', 'type' => 'Master'],
            self::GROUP_DOMAIN => ['name' => 'Group.example', 'type' => 'Native'],
            self::FOREIGN_DOMAIN => ['name' => 'foreign.example', 'type' => 'Master'],
        ][$id] ?? null);
        $backend->method('countZoneRecords')->willReturnCallback(fn(int $id): int => $id === self::DIRECT_DOMAIN ? 2 : 0);

        return $backend;
    }

    public function testEditOthersListsEveryLinkedZoneWithARow(): void
    {
        $this->actingAs(self::ADMIN_USER_ID);

        $ids = $this->model($this->dnsBackendStub(false))->getListZoneUseTempl(self::TEMPLATE, self::ADMIN_USER_ID);

        // The stale link (no domains row) and the other template's zone are left out.
        $this->assertEqualsCanonicalizing([self::DIRECT_DOMAIN, self::GROUP_DOMAIN, self::FOREIGN_DOMAIN], $ids);
    }

    public function testEditOwnListsDirectlyAndGroupOwnedZones(): void
    {
        $this->actingAs(self::OWN_EDITOR);

        $ids = $this->model($this->dnsBackendStub(false))->getListZoneUseTempl(self::TEMPLATE, self::OWN_EDITOR);

        // Ownership is dual: zones.owner or membership of an owning group (zones_groups)
        $this->assertSame([self::DIRECT_DOMAIN, self::GROUP_DOMAIN], $ids);
    }

    public function testAZoneWithSeveralOwnersIsListedOnce(): void
    {
        // A second ownership row for the direct zone: sync tracking needs both id pairs,
        // the count and detail listings want the zone once
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id) VALUES
            (6, " . self::DIRECT_DOMAIN . ", " . self::OTHER_USER . ", 'co-owner', " . self::TEMPLATE . ")");
        $this->actingAs(self::ADMIN_USER_ID);
        $model = $this->model($this->dnsBackendStub(false));

        $ids = $model->getListZoneUseTempl(self::TEMPLATE, self::ADMIN_USER_ID);
        $details = array_column($model->getZonesUsingTemplate(self::TEMPLATE, self::ADMIN_USER_ID), 'id');
        $pairs = array_column($model->getZoneAndDomainIdsByTemplate(self::TEMPLATE, self::ADMIN_USER_ID), 'zone_id');

        $this->assertSame(1, count(array_keys($ids, self::DIRECT_DOMAIN, true)));
        $this->assertSame(1, count(array_keys($details, self::DIRECT_DOMAIN, true)));
        $this->assertContains(1, $pairs);
        $this->assertContains(6, $pairs);
    }

    public function testEditOwnFilterIsKeyedByTheGivenUserNotTheSession(): void
    {
        $this->actingAs(self::OWN_EDITOR);

        $ids = $this->model($this->dnsBackendStub(false))->getListZoneUseTempl(self::TEMPLATE, self::OTHER_USER);

        $this->assertSame([self::GROUP_DOMAIN], $ids);
    }

    public function testApiBackendListsIdsFromTheZonesTableAlone(): void
    {
        $this->actingAs(self::ADMIN_USER_ID);
        $this->db->exec("DROP TABLE domains");

        $ids = $this->model($this->apiBackend())->getListZoneUseTempl(self::TEMPLATE, self::ADMIN_USER_ID);

        $this->assertSame([self::DIRECT_DOMAIN, self::GROUP_DOMAIN, self::FOREIGN_DOMAIN, self::ORPHAN_DOMAIN], $ids);

        $this->actingAs(self::OWN_EDITOR);

        $ids = $this->model($this->apiBackend())->getListZoneUseTempl(self::TEMPLATE, self::OWN_EDITOR);

        $this->assertSame([self::DIRECT_DOMAIN, self::GROUP_DOMAIN, self::ORPHAN_DOMAIN], $ids);
    }

    public function testIdPairsCarryBothIdsAndSkipStaleLinks(): void
    {
        $this->actingAs(self::ADMIN_USER_ID);

        $rows = $this->model($this->dnsBackendStub(false))->getZoneAndDomainIdsByTemplate(self::TEMPLATE, self::ADMIN_USER_ID);

        $this->assertSame([
            ['zone_id' => 1, 'domain_id' => self::DIRECT_DOMAIN],
            ['zone_id' => 2, 'domain_id' => self::GROUP_DOMAIN],
            ['zone_id' => 3, 'domain_id' => self::FOREIGN_DOMAIN],
        ], $rows);
    }

    public function testIdPairsFollowTheOwnerFilter(): void
    {
        $this->actingAs(self::OWN_EDITOR);

        $rows = $this->model($this->dnsBackendStub(false))->getZoneAndDomainIdsByTemplate(self::TEMPLATE, self::OWN_EDITOR);

        $this->assertSame([
            ['zone_id' => 1, 'domain_id' => self::DIRECT_DOMAIN],
            ['zone_id' => 2, 'domain_id' => self::GROUP_DOMAIN],
        ], $rows);
    }

    public function testIdPairsOnTheApiBackendKeepStaleLinks(): void
    {
        $this->actingAs(self::OWN_EDITOR);
        $this->db->exec("DROP TABLE domains");

        $rows = $this->model($this->apiBackend())->getZoneAndDomainIdsByTemplate(self::TEMPLATE, self::OWN_EDITOR);

        $this->assertSame([
            ['zone_id' => 1, 'domain_id' => self::DIRECT_DOMAIN],
            ['zone_id' => 2, 'domain_id' => self::GROUP_DOMAIN],
            ['zone_id' => 5, 'domain_id' => self::ORPHAN_DOMAIN],
        ], $rows);
    }

    public function testZoneDetailsComeSortedByNameWithCountsAndOwners(): void
    {
        $this->actingAs(self::ADMIN_USER_ID);

        $rows = $this->model($this->dnsBackendStub(false))->getZonesUsingTemplate(self::TEMPLATE, self::ADMIN_USER_ID);

        $this->assertArrayHasKey(0, $rows[0], 'Rows keep the positional columns of FETCH_BOTH.');
        $this->assertSame([
            [
                'id' => self::DIRECT_DOMAIN,
                'name' => 'direct.example',
                'type' => 'MASTER',
                'count_records' => 2,
                'owner' => self::OWN_EDITOR,
                'comment' => 'mine',
                'owner_name' => 'editor',
                'owner_fullname' => 'Own Editor',
            ],
            [
                'id' => self::FOREIGN_DOMAIN,
                'name' => 'foreign.example',
                'type' => 'MASTER',
                'count_records' => 1,
                'owner' => self::ADMIN_USER_ID,
                'comment' => null,
                'owner_name' => 'admin',
                'owner_fullname' => 'Admin User',
            ],
            [
                'id' => self::GROUP_DOMAIN,
                'name' => 'group.example',
                'type' => 'NATIVE',
                'count_records' => null,
                'owner' => self::OTHER_USER,
                'comment' => 'via group',
                'owner_name' => 'other',
                'owner_fullname' => 'Other User',
            ],
        ], $this->namedColumns($rows));
    }

    public function testZoneDetailsFollowTheOwnerFilter(): void
    {
        $this->actingAs(self::OWN_EDITOR);

        $rows = $this->model($this->dnsBackendStub(false))->getZonesUsingTemplate(self::TEMPLATE, self::OWN_EDITOR);

        $this->assertSame([self::DIRECT_DOMAIN, self::GROUP_DOMAIN], array_column($rows, 'id'));
    }

    public function testZoneDetailsOnTheApiBackendComeFromTheClient(): void
    {
        $this->actingAs(self::ADMIN_USER_ID);
        $this->db->exec("DROP TABLE domains");
        $this->db->exec("DROP TABLE records");

        $rows = $this->model($this->apiBackend())->getZonesUsingTemplate(self::TEMPLATE, self::ADMIN_USER_ID);

        // Sorted case-insensitively by the client's name; an unknown zone yields blanks.
        $this->assertSame([
            [
                'id' => self::ORPHAN_DOMAIN,
                'name' => '',
                'type' => '',
                'count_records' => 0,
                'owner' => self::OWN_EDITOR,
                'comment' => 'stale',
                'owner_name' => 'editor',
                'owner_fullname' => 'Own Editor',
            ],
            [
                'id' => self::DIRECT_DOMAIN,
                'name' => 'direct.example',
                'type' => 'Master',
                'count_records' => 2,
                'owner' => self::OWN_EDITOR,
                'comment' => 'mine',
                'owner_name' => 'editor',
                'owner_fullname' => 'Own Editor',
            ],
            [
                'id' => self::FOREIGN_DOMAIN,
                'name' => 'foreign.example',
                'type' => 'Master',
                'count_records' => 0,
                'owner' => self::ADMIN_USER_ID,
                'comment' => null,
                'owner_name' => 'admin',
                'owner_fullname' => 'Admin User',
            ],
            [
                'id' => self::GROUP_DOMAIN,
                'name' => 'Group.example',
                'type' => 'Native',
                'count_records' => 0,
                'owner' => self::OTHER_USER,
                'comment' => 'via group',
                'owner_name' => 'other',
                'owner_fullname' => 'Other User',
            ],
        ], $rows);
    }

    public function testZoneDetailsOnTheApiBackendFollowTheOwnerFilter(): void
    {
        $this->actingAs(self::OWN_EDITOR);

        $rows = $this->model($this->apiBackend())->getZonesUsingTemplate(self::TEMPLATE, self::OWN_EDITOR);

        $this->assertSame([self::ORPHAN_DOMAIN, self::DIRECT_DOMAIN, self::GROUP_DOMAIN], array_column($rows, 'id'));
    }

    public function testZonesByIdsWithNoIdsAsksNobody(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('getZonesByIds');

        $this->assertSame([], $this->model($backend)->getZonesByIds([]));
    }

    public function testZonesByIdsGoThroughTheBackendWhenOneIsWired(): void
    {
        $backend = $this->dnsBackendStub(true);
        $backend->expects($this->once())->method('getZonesByIds')
            ->with([self::DIRECT_DOMAIN, self::GROUP_DOMAIN])
            ->willReturn([['id' => self::GROUP_DOMAIN, 'name' => 'group.example', 'type' => 'Native']]);

        $this->assertSame(
            [['id' => self::GROUP_DOMAIN, 'name' => 'group.example', 'type' => 'Native']],
            $this->model($backend)->getZonesByIds([self::DIRECT_DOMAIN, self::GROUP_DOMAIN])
        );
    }

    public function testZonesByIdsReadTheDomainsTableWithoutABackend(): void
    {
        // The model always carries a provider; only the static read accessors build the repository without one.
        $rows = (new DbZoneTemplateRepository($this->db, $this->config))->getZonesByIds([self::GROUP_DOMAIN, self::DIRECT_DOMAIN, self::ORPHAN_DOMAIN]);

        $this->assertArrayHasKey(0, $rows[0], 'Rows keep the positional columns of FETCH_BOTH.');
        $this->assertSame([
            ['id' => self::DIRECT_DOMAIN, 'name' => 'direct.example', 'type' => 'MASTER'],
            ['id' => self::GROUP_DOMAIN, 'name' => 'group.example', 'type' => 'NATIVE'],
        ], $this->namedColumns($rows));
    }
}
