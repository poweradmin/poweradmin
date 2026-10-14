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
 */

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PDO;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * API backend mode. b.example (row 12) carries domain_id 5 and c.example (row 5) carries
 * domain_id 3, so id 5 opens b.example and, once its row is gone, c.example by row id.
 * Keys, grants and extra owners on 5 were meant for b.example and must not reach c.example.
 */
class SharedZoneIdRemovalPathsTest extends SqliteIntegrationTestCase
{
    private const B_OWNER = 7;
    private const C_OWNER = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0,
            comment TEXT, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, owner INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, api_key_id INTEGER NOT NULL,
            zone_id INTEGER NOT NULL, UNIQUE (api_key_id, zone_id))");

        foreach (['fullname TEXT', 'email TEXT', 'description TEXT', 'active INTEGER DEFAULT 1', 'use_ldap INTEGER DEFAULT 0', 'auth_method TEXT'] as $column) {
            $this->db->exec("ALTER TABLE users ADD COLUMN $column");
        }
        $this->db->exec("ALTER TABLE perm_templ ADD COLUMN template_type TEXT DEFAULT 'user'");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (7, 'bowner', 1), (8, 'cowner', 1), (10, 'heir', 1)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name, zone_type) VALUES
            (12, 5, 7, 'b.example', 'MASTER'), (5, 3, 8, 'c.example', 'SLAVE'), (30, 30, 7, 'd.example', 'MASTER'),
            (40, 5, 9, NULL, NULL)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 1), (3, 2)");
        // Key 1 is restricted to b.example, key 2 to c.example, key 3 to d.example
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 5), (2, 3), (3, 30)");
    }

    /** @return array<int, int[]> */
    private function keyScopes(): array
    {
        $scopes = [];
        foreach ($this->db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(int)$row['api_key_id']][] = (int)$row['zone_id'];
        }

        return $scopes;
    }

    /** @return int[] */
    private function idsOf(string $sql): array
    {
        return array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function apiBackend(): object
    {
        $backend = $this->dnsBackendStub(true);
        $backend->method('deleteZone')->willReturn(true);
        $backend->method('getZoneNameById')->willReturn('b.example');

        return $backend;
    }

    private function domainManager(): DomainManager
    {
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainNameById')->willReturn('b.example');
        $repo->method('getDomainType')->willReturn('MASTER');

        return new DomainManager(
            $this->db,
            $this->primeConfig('sql'),
            $this->createMock(SOARecordManagerInterface::class),
            $repo,
            $this->apiBackend(),
            null,
            $this->createMock(RecordChangeLogger::class)
        );
    }

    private function primeConfig(string $backend): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settings = $reflection->getProperty('settings');
        $settings->setAccessible(true);
        $initialized = $reflection->getProperty('initialized');
        $initialized->setAccessible(true);
        $settings->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['backend' => $backend],
            'pdns_api' => ['url' => 'http://127.0.0.1:1', 'key' => 'test', 'server_name' => 'localhost'],
        ]);
        $initialized->setValue($config, true);

        return $config;
    }

    #[RunInSeparateProcess]
    public function testDeletingTheZoneAnIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $this->assertTrue($this->domainManager()->deleteDomain(5));

        $this->assertSame([5, 30], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    #[RunInSeparateProcess]
    public function testUndoingAFailedCreationOfTheZoneAnIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $cleanup = new \ReflectionMethod(DomainManager::class, 'cleanupZoneMetadata');
        $cleanup->setAccessible(true);
        $cleanup->invoke($this->domainManager(), 5);

        $this->assertSame([5, 30], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    #[RunInSeparateProcess]
    public function testDeletingSeveralZonesLeavesTheKeysOfAnIdThatOpensAnotherZoneMatchingNoZone(): void
    {
        $this->assertTrue($this->domainManager()->deleteDomains([5]));

        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    #[RunInSeparateProcess]
    public function testDeletingAZoneWhoseIdOpensNothingElseKeepsItsApiKeys(): void
    {
        $this->assertTrue($this->domainManager()->deleteDomain(3));

        $this->assertSame([12, 30, 40], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    #[RunInSeparateProcess]
    public function testDeletingAUserWhoseZoneAnIdOpensDropsTheKeysGrantsAndExtraOwnersOnIt(): void
    {
        $this->assertTrue((new UserManager($this->db, $this->primeConfig('api')))->deleteUser(self::B_OWNER, []));

        // c.example now opens on id 5; nothing meant for b.example may reach it
        $this->assertSame([5], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    #[RunInSeparateProcess]
    public function testDeletingAUserWhoseZoneIdOpensNothingElseKeepsTheKeysAndGrants(): void
    {
        $this->assertTrue((new UserManager($this->db, $this->primeConfig('api')))->deleteUser(self::C_OWNER, []));

        $this->assertSame([12, 30, 40], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3, 5], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    #[RunInSeparateProcess]
    public function testHandingADeletedUsersZonesToANewOwnerKeepsTheirRowsAndIds(): void
    {
        // The heir already holds an extra-owner row on b.example
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (41, 5, 10, NULL)");

        $this->assertTrue((new UserManager($this->db, $this->primeConfig('api')))->deleteUser(self::B_OWNER, [
            ['zid' => 5, 'target' => 'new_owner', 'newowner' => 10],
            ['zid' => 30, 'target' => 'new_owner', 'newowner' => self::C_OWNER],
        ]));

        $owners = [];
        foreach ($this->db->query("SELECT id, owner FROM zones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owners[(int)$row['id']] = (int)$row['owner'];
        }
        // b.example keeps row 12 and id 5 under the heir, with no duplicate extra row; c.example is untouched
        $this->assertSame([5 => self::C_OWNER, 12 => 10, 30 => self::C_OWNER, 40 => 9], $owners);
        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3, 5], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    #[RunInSeparateProcess]
    public function testAHandoverOnASharedIdMovesOnlyTheZoneTheIdOpens(): void
    {
        // Id 70 opens q.example (user 20's); p.example of the deleted user shares it
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (70, NULL, 7, 'p.example'), (71, 70, 20, 'q.example')");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (220, 'user_edit_others'), (221, 'zone_meta_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (20, 'limited')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (20, 220), (20, 221)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (20, 'limited', 20)");
        $_SESSION['userid'] = 20;

        (new UserManager($this->db, $this->primeConfig('api')))->deleteUser(self::B_OWNER, [
            ['zid' => 70, 'target' => 'new_owner', 'newowner' => 20],
        ]);

        // Owning q.example does not hand the user p.example
        $this->assertSame([71], $this->idsOf("SELECT id FROM zones WHERE id IN (70, 71) ORDER BY id"));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones WHERE zone_name = 'p.example'")->fetchColumn());
        $this->assertSame(20, (int)$this->db->query("SELECT owner FROM zones WHERE id = 71")->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testAHandoverOfAZoneASharedIdDoesNotOpenAddsNoOwnerToTheZoneItOpens(): void
    {
        // Id 70 opens q.example (user 20's); p.example of the deleted user shares it
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (70, NULL, 7, 'p.example'), (71, 70, 20, 'q.example')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (20, 'qowner', 1)");

        (new UserManager($this->db, $this->primeConfig('api')))->deleteUser(self::B_OWNER, [
            ['zid' => 70, 'target' => 'new_owner', 'newowner' => self::C_OWNER],
        ]);

        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones WHERE domain_id = 70 AND owner = " . self::C_OWNER)->fetchColumn());
        $this->assertSame(20, (int)$this->db->query("SELECT owner FROM zones WHERE id = 71")->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testTheConsistencyCheckDeletingTheZoneAnIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $service = new DatabaseConsistencyService($this->db, $this->primeConfig('api'), $this->apiBackend());

        $this->assertTrue($service->deleteSlaveZone(5));

        $this->assertSame([5, 30], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    #[RunInSeparateProcess]
    public function testTheConsistencyCheckDeletingAZoneWhoseIdOpensNothingElseKeepsItsApiKeys(): void
    {
        $service = new DatabaseConsistencyService($this->db, $this->primeConfig('api'), $this->apiBackend());

        $this->assertTrue($service->deleteSlaveZone(3));

        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
    }
}
