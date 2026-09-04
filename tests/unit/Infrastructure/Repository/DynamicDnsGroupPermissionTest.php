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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;

/**
 * DDNS authentication must accept a zone_content_edit_* grant that comes from a
 * group template, not just the user's direct template (audit M7). The web UI
 * honors group permissions, so a group-only editor should not get badauth.
 */
class DynamicDnsGroupPermissionTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, use_ldap INTEGER DEFAULT 0, active INTEGER DEFAULT 1, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'zone_content_edit_own'), (2, 'search')");
        // Templates: 10 grants edit, 11 does not, 12 grants edit (assigned to a group).
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (10, 'DirectEdit'), (11, 'NoEdit'), (12, 'GroupEdit')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (10, 1), (11, 2), (12, 1)");

        // A: edit via direct template. B: edit only via group. C: no edit. D: direct edit but inactive.
        $this->db->exec("INSERT INTO users (id, username, password, perm_templ, active) VALUES
            (1, 'direct', 'h', 10, 1),
            (2, 'grouped', 'h', 11, 1),
            (3, 'noperm', 'h', 11, 1),
            (4, 'inactive', 'h', 10, 0)");

        // Group 100 (Editors, templ 12=edit) owns z1; group 101 (Viewers, templ 11=no edit) owns z2.
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (100, 'Editors', 12), (101, 'Viewers', 11)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 100), (2, 101)");

        // z1 owned by the edit group, z2 by the no-edit group, z3 directly by the direct-edit user.
        $this->db->exec("INSERT INTO domains (id, name) VALUES (201, 'z1.example'), (202, 'z2.example'), (203, 'z3.example')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (203, 1)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (201, 100), (202, 101)");
    }

    private function repository(): SqlDynamicDnsRepository
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        return new SqlDynamicDnsRepository($this->db, $soa, 'records', 'domains');
    }

    public function testUserWithDirectEditPermissionAuthenticates(): void
    {
        $user = $this->repository()->findUserByUsernameWithDynamicDnsPermissions('direct');
        $this->assertNotNull($user);
        $this->assertSame(1, $user->getId());
    }

    public function testUserWithGroupOnlyEditPermissionAuthenticates(): void
    {
        // The fix: edit permission inherited from a group template must be honored.
        $user = $this->repository()->findUserByUsernameWithDynamicDnsPermissions('grouped');
        $this->assertNotNull($user);
        $this->assertSame(2, $user->getId());
    }

    public function testUserWithoutEditPermissionIsRejected(): void
    {
        $this->assertNull($this->repository()->findUserByUsernameWithDynamicDnsPermissions('noperm'));
    }

    public function testInactiveUserIsRejected(): void
    {
        $this->assertNull($this->repository()->findUserByUsernameWithDynamicDnsPermissions('inactive'));
    }

    public function testGetUserZonesReturnsOnlyZonesTheEditGroupOwns(): void
    {
        // User 2 (grouped) edits via group 100 (owns z1) and is also in group 101
        // (owns z2, no edit). Only z1 is updatable - z2 must NOT leak through.
        $zones = $this->repository()->getUserZones(new User(2, 'h', false));

        $this->assertArrayHasKey(201, $zones);
        $this->assertArrayNotHasKey(202, $zones);
    }

    public function testGetUserZonesReturnsDirectlyOwnedEditableZone(): void
    {
        // User 1 (direct) has edit via their own template and owns z3 directly.
        $zones = $this->repository()->getUserZones(new User(1, 'h', false));

        $this->assertArrayHasKey(203, $zones);
    }
}
