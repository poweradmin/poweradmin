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
 * Defense-in-depth coverage for DomainManager::changeZoneType() and
 * changeZoneSlaveMaster(). The EditController already gates these on
 * zone_meta_edit_own / zone_meta_edit_others, but the service layer must
 * not assume the caller checked: a future API or CLI caller that bypasses
 * the controller would silently rewrite zone metadata otherwise.
 *
 * Each test runs in a fresh process because UserManager::verifyPermission
 * caches the first lookup in a static and reuses it across calls.
 */
class DomainManagerZoneMetaPermissionTest extends SqliteIntegrationTestCase
{
    private const NON_PRIVILEGED_USER_ID = 100;
    private const NON_PRIVILEGED_PERM_TEMPL_ID = 100;

    private const META_EDIT_OWN_USER_ID = 101;
    private const META_EDIT_OWN_PERM_TEMPL_ID = 101;

    private const META_EDIT_OTHERS_USER_ID = 102;
    private const META_EDIT_OTHERS_PERM_TEMPL_ID = 102;

    private const ZONE_DOMAIN_ID = 555;
    private const ZONES_PRIMARY_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");

        $this->seedZoneOwnedBy(self::META_EDIT_OWN_USER_ID);
        $this->seedAlternatePermSets();
    }

    #[RunInSeparateProcess]
    public function testChangeZoneTypeRefusedWithoutMetaEditPermissions(): void
    {
        $_SESSION['userid'] = self::NON_PRIVILEGED_USER_ID;

        $domainManager = $this->makeDomainManager();

        $this->assertFalse(
            $domainManager->changeZoneType('MASTER', self::ZONE_DOMAIN_ID),
            'changeZoneType must reject callers lacking zone_meta_edit permissions even when the controller gate is bypassed.'
        );
    }

    #[RunInSeparateProcess]
    public function testChangeZoneTypeAllowedForOwnerWithMetaEditOwn(): void
    {
        $_SESSION['userid'] = self::META_EDIT_OWN_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())
            ->method('updateZoneType')
            ->with(self::ZONE_DOMAIN_ID, 'MASTER')
            ->willReturn(true);

        $domainManager = $this->makeDomainManager($backend);

        $this->assertTrue(
            $domainManager->changeZoneType('MASTER', self::ZONE_DOMAIN_ID)
        );
    }

    #[RunInSeparateProcess]
    public function testChangeZoneTypeAllowedForNonOwnerWithMetaEditOthers(): void
    {
        $_SESSION['userid'] = self::META_EDIT_OTHERS_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())
            ->method('updateZoneType')
            ->with(self::ZONE_DOMAIN_ID, 'MASTER')
            ->willReturn(true);

        $domainManager = $this->makeDomainManager($backend);

        $this->assertTrue(
            $domainManager->changeZoneType('MASTER', self::ZONE_DOMAIN_ID)
        );
    }

    #[RunInSeparateProcess]
    public function testChangeZoneSlaveMasterRefusedWithoutMetaEditPermissions(): void
    {
        $_SESSION['userid'] = self::NON_PRIVILEGED_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('updateZoneMaster');

        $domainManager = $this->makeDomainManager($backend);

        $this->assertFalse(
            $domainManager->changeZoneSlaveMaster(self::ZONE_DOMAIN_ID, '192.0.2.10')
        );
    }

    #[RunInSeparateProcess]
    public function testChangeZoneSlaveMasterAllowedForOwnerWithMetaEditOwn(): void
    {
        $_SESSION['userid'] = self::META_EDIT_OWN_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())
            ->method('updateZoneMaster')
            ->with(self::ZONE_DOMAIN_ID, '192.0.2.10')
            ->willReturn(true);

        $domainManager = $this->makeDomainManager($backend);

        $this->assertTrue(
            $domainManager->changeZoneSlaveMaster(self::ZONE_DOMAIN_ID, '192.0.2.10')
        );
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

    private function seedAlternatePermSets(): void
    {
        // Reserve perm_items ids that don't collide with the base class's
        // 47/53. Real prod ids differ; only the names matter to verifyPermission.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES
            (200, 'zone_meta_edit_own'),
            (201, 'zone_meta_edit_others'),
            (202, 'zone_content_view_own')");

        // Empty permission template - 'search' / 'zone_content_view_own' style
        // perms a basic user has, none of which permit metadata edits.
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::NON_PRIVILEGED_PERM_TEMPL_ID . ", 'Read-only')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::NON_PRIVILEGED_PERM_TEMPL_ID . ", 202)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::NON_PRIVILEGED_USER_ID . ", 'viewer', " . self::NON_PRIVILEGED_PERM_TEMPL_ID . ")");

        // Owner-only: zone_meta_edit_own.
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::META_EDIT_OWN_PERM_TEMPL_ID . ", 'OwnerMetaEdit')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::META_EDIT_OWN_PERM_TEMPL_ID . ", 200)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::META_EDIT_OWN_USER_ID . ", 'owner', " . self::META_EDIT_OWN_PERM_TEMPL_ID . ")");

        // Global meta edit: zone_meta_edit_others (user does NOT own the zone).
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::META_EDIT_OTHERS_PERM_TEMPL_ID . ", 'GlobalMetaEdit')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::META_EDIT_OTHERS_PERM_TEMPL_ID . ", 201)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::META_EDIT_OTHERS_USER_ID . ", 'manager', " . self::META_EDIT_OTHERS_PERM_TEMPL_ID . ")");
    }

    private function makeDomainManager(?object $backend = null): DomainManager
    {
        $config = $this->primeConfig();
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $repo = $this->createMock(DomainRepositoryInterface::class);
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
