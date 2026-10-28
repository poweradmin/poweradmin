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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateSyncRepository;
use TestHelpers\RecordCommentFixture;

/**
 * Templates shared with user groups (#1267) on each engine: the list, the sync status
 * and the share lookups see a template through the user's groups, with MySQL's native
 * prepares, and a share goes with its template.
 */
class ZoneTemplateGroupShareIntegrationTest extends TestCase
{
    private const MEMBER = 5;
    private const OWNER = 6;
    private const OUTSIDER = 7;

    private ?PDO $db = null;
    private string $engine = '';

    private static function scratch(): string
    {
        return 'poweradmin_it_template_shares_' . getmypid();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->dropScratch($this->db, $this->engine);
            $this->db = null;
        }
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return RecordCommentFixture::engines();
    }

    private function open(string $engine): DbZoneTemplateRepository
    {
        try {
            $db = RecordCommentFixture::connect($engine);
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }
        if ($engine === 'mysql') {
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        $this->db = $db;
        $this->engine = $engine;
        $this->dropScratch($db, $engine);
        if ($engine === 'mysql') {
            $db->exec('CREATE DATABASE ' . self::scratch());
            $db->exec('USE ' . self::scratch());
        } elseif ($engine === 'pgsql') {
            $db->exec('CREATE SCHEMA ' . self::scratch());
            $db->exec('SET search_path TO ' . self::scratch());
        }

        [$id, $int, $text, $flag] = match ($engine) {
            'mysql' => ['INT AUTO_INCREMENT PRIMARY KEY', 'INT', 'VARCHAR(255)', 'TINYINT(1) NOT NULL DEFAULT 0'],
            'pgsql' => ['SERIAL PRIMARY KEY', 'INT', 'VARCHAR(255)', "BOOL NOT NULL DEFAULT 'f'"],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER', 'TEXT', 'BOOLEAN NOT NULL DEFAULT 0'],
        };
        foreach (
            [
                "CREATE TABLE users (id $id, username $text, fullname $text)",
                "CREATE TABLE zone_templ (id $id, name $text NOT NULL, descr $text NOT NULL, owner $int NOT NULL, created_by $int, is_default $flag)",
                "CREATE TABLE zone_templ_records (id $id, zone_templ_id $int NOT NULL, name $text, type $text, content $text, ttl $int, prio $int)",
                "CREATE TABLE records_zone_templ (id $id, domain_id $int, record_id $int, zone_templ_id $int)",
                "CREATE TABLE records_zone_templ_api (id $id, domain_id $int, record_id $text, zone_templ_id $int)",
                "CREATE TABLE zones (id $id, domain_id $int, owner $int, zone_templ_id $int NOT NULL DEFAULT 0)",
                "CREATE TABLE zone_template_sync (id $id, zone_id $int NOT NULL, zone_templ_id $int NOT NULL, needs_sync $flag)",
                "CREATE TABLE user_groups (id $id, name $text NOT NULL)",
                "CREATE TABLE user_group_members (id $id, user_id $int NOT NULL, group_id $int NOT NULL)",
                "CREATE TABLE zone_templ_groups (id $id, zone_templ_id $int NOT NULL, group_id $int NOT NULL, UNIQUE (zone_templ_id, group_id))",
            ] as $ddl
        ) {
            $db->exec($ddl);
        }
        $db->exec("INSERT INTO user_groups (id, name) VALUES (3, 'Ops'), (4, 'Billing')");
        $db->exec('INSERT INTO user_group_members (user_id, group_id) VALUES (' . self::MEMBER . ', 3), (' . self::OWNER . ', 4)');

        return new DbZoneTemplateRepository($db, RecordCommentFixture::config($engine));
    }

    private function dropScratch(PDO $db, string $engine): void
    {
        if ($engine === 'mysql') {
            $db->exec('DROP DATABASE IF EXISTS ' . self::scratch());
        } elseif ($engine === 'pgsql') {
            $db->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
        }
    }

    #[DataProvider('engines')]
    public function testAMemberSeesTheSharedTemplateWithTheirGroupsOnly(string $engine): void
    {
        $repository = $this->open($engine);
        $shared = $repository->createZoneTemplate('shared', '', self::OWNER, self::OWNER);
        $repository->createZoneTemplate('private', '', self::OWNER, self::OWNER);
        $repository->createZoneTemplate('global', '', 0, self::OWNER);
        $repository->replaceTemplateGroups($shared, [3, 4]);

        $listed = array_column($repository->listZoneTemplates(self::MEMBER, false), null, 'name');
        $this->assertSame(['global', 'shared'], array_keys($listed));
        $this->assertSame([['id' => 3, 'name' => 'Ops']], $listed['shared']['groups']);
        $this->assertSame(['global'], array_column($repository->listZoneTemplates(self::OUTSIDER, false), 'name'));

        $this->assertTrue($repository->isSharedWithUser($shared, self::MEMBER));
        $this->assertFalse($repository->isSharedWithUser($shared, self::OUTSIDER));
        $this->assertSame(['Billing', 'Ops'], array_column($repository->listGroupsForTemplates([$shared], self::OWNER)[$shared], 'name'));

        $sync = new DbZoneTemplateSyncRepository($this->db, RecordCommentFixture::config($engine));
        $this->assertArrayHasKey($shared, $sync->getTemplateSyncStatus(self::MEMBER));
        $this->assertArrayNotHasKey($shared, $sync->getTemplateSyncStatus(self::OUTSIDER));
    }

    #[DataProvider('engines')]
    public function testDeletingATemplateDropsItsShares(string $engine): void
    {
        $repository = $this->open($engine);
        $first = $repository->createZoneTemplate('first', '', self::OWNER, self::OWNER);
        $kept = $repository->createZoneTemplate('kept', '', self::MEMBER, self::MEMBER);
        $repository->replaceTemplateGroups($first, [3]);
        $repository->replaceTemplateGroups($kept, [3]);

        $repository->deleteZoneTemplatesOwnedBy(self::OWNER);

        $this->assertSame([$kept], array_map('intval', $this->db->query('SELECT zone_templ_id FROM zone_templ_groups')->fetchAll(PDO::FETCH_COLUMN)));
        $repository->deleteZoneTemplate($kept);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM zone_templ_groups')->fetchColumn());
    }
}
