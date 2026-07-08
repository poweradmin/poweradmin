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
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Zone deletion must be gated on the DELETE permission, not the EDIT
 * permission. Historically DomainManager::deleteDomain()/deleteDomains()
 * checked zone_content_edit_*, which let an edit-only user delete zones
 * (too permissive) while blocking a delete-only user (too strict).
 *
 * Each test runs in a fresh process because UserManager::verifyPermission
 * caches the first lookup in a static and reuses it across calls.
 */
class DomainManagerDeletePermissionTest extends SqliteIntegrationTestCase
{
    private const EDIT_ONLY_USER_ID = 110;
    private const DELETE_OWN_USER_ID = 111;
    private const DELETE_OTHERS_USER_ID = 112;
    private const NON_PRIV_USER_ID = 113;

    private const ZONE_DOMAIN_ID = 555;
    private const ZONES_PRIMARY_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0)");

        $this->seedUsers();
    }

    #[RunInSeparateProcess]
    public function testDeleteRefusedForOwnerWithOnlyEditPermission(): void
    {
        $this->seedZoneOwnedBy(self::EDIT_ONLY_USER_ID);
        $_SESSION['userid'] = self::EDIT_ONLY_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteZone');

        $manager = $this->makeDomainManager($backend);

        $this->assertFalse(
            $manager->deleteDomain(self::ZONE_DOMAIN_ID),
            'A zone owner holding only zone_content_edit_own must not be able to delete the zone.'
        );
    }

    #[RunInSeparateProcess]
    public function testDeleteRefusedForNonPrivilegedUser(): void
    {
        $this->seedZoneOwnedBy(self::NON_PRIV_USER_ID);
        $_SESSION['userid'] = self::NON_PRIV_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteZone');

        $manager = $this->makeDomainManager($backend);

        $this->assertFalse($manager->deleteDomain(self::ZONE_DOMAIN_ID));
    }

    #[RunInSeparateProcess]
    public function testDeleteAllowedForOwnerWithDeleteOwn(): void
    {
        $this->seedZoneOwnedBy(self::DELETE_OWN_USER_ID);
        $_SESSION['userid'] = self::DELETE_OWN_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())
            ->method('deleteZone')
            ->with(self::ZONE_DOMAIN_ID, 'example.com')
            ->willReturn(true);

        $manager = $this->makeDomainManager($backend);

        $this->assertTrue($manager->deleteDomain(self::ZONE_DOMAIN_ID));
    }

    #[RunInSeparateProcess]
    public function testDeleteAllowedForNonOwnerWithDeleteOthers(): void
    {
        // Zone owned by someone else; caller only holds zone_delete_others.
        $this->seedZoneOwnedBy(self::DELETE_OWN_USER_ID);
        $_SESSION['userid'] = self::DELETE_OTHERS_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())
            ->method('deleteZone')
            ->with(self::ZONE_DOMAIN_ID, 'example.com')
            ->willReturn(true);

        $manager = $this->makeDomainManager($backend);

        $this->assertTrue($manager->deleteDomain(self::ZONE_DOMAIN_ID));
    }

    private function seedZoneOwnedBy(int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (:id, :did, :owner, 0)"
        );
        $stmt->execute([
            ':id' => self::ZONES_PRIMARY_ID,
            ':did' => self::ZONE_DOMAIN_ID,
            ':owner' => $userId,
        ]);
    }

    private function seedUsers(): void
    {
        // Fresh perm_item ids that don't collide with the base class seed (47/53).
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES
            (210, 'zone_content_edit_own'),
            (211, 'zone_delete_own'),
            (212, 'zone_delete_others'),
            (213, 'zone_content_view_own')");

        $this->createUserWithPerm(self::EDIT_ONLY_USER_ID, 'editonly', 210);
        $this->createUserWithPerm(self::DELETE_OWN_USER_ID, 'deleteown', 211);
        $this->createUserWithPerm(self::DELETE_OTHERS_USER_ID, 'deleteothers', 212);
        $this->createUserWithPerm(self::NON_PRIV_USER_ID, 'viewer', 213);
    }

    private function createUserWithPerm(int $userId, string $username, int $permItemId): void
    {
        // Reuse the user id as the perm template id to keep the seed simple.
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES ($userId, '$username-templ')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES ($userId, $permItemId)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES ($userId, '$username', $userId)");
    }

    private function makeDomainManager(?object $backend = null): DomainManager
    {
        $config = $this->primeConfig();
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainNameById')->willReturn('example.com');
        $repo->method('getDomainType')->willReturn('MASTER');
        $changeLogger = $this->createMock(RecordChangeLogger::class);

        return new DomainManager(
            $this->db,
            $config,
            $soa,
            $repo,
            $backend ?? $this->dnsBackendStub(false),
            null,
            $changeLogger
        );
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $settingsProperty->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ]);
        $initializedProperty->setValue($config, true);

        return $config;
    }
}
