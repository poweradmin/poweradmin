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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\MfaVerificationMailerInterface;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Auth\BaconQrCodeRenderer;
use Poweradmin\Infrastructure\Auth\Google2FaTotp;
use TestHelpers\FakeConfiguration;

/**
 * user_enforce_mfa reaches a user through their own template or a group's template,
 * on the real SQL of MfaService, and never through another user's grant.
 */
class MfaEnforcementPermissionIntegrationTest extends TestCase
{
    private const ENFORCING_TEMPL = 1;
    private const PLAIN_TEMPL = 2;
    private const ENFORCING_GROUP_TEMPL = 3;
    private const PLAIN_GROUP_TEMPL = 4;

    private PDO $db;
    private MfaService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)');
        $this->db->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)');
        $this->db->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)');
        $this->db->exec('CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)');

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_enforce_mfa'), (2, 'zone_content_view_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'Enforcing'), (2, 'Plain'), (3, 'Enforcing group'), (4, 'Plain group')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES '
            . '(' . self::ENFORCING_TEMPL . ', 1), (' . self::ENFORCING_TEMPL . ', 2), (' . self::PLAIN_TEMPL . ', 2), '
            . '(' . self::ENFORCING_GROUP_TEMPL . ', 1), (' . self::PLAIN_GROUP_TEMPL . ', 2)');
        $this->db->exec('INSERT INTO user_groups (id, name, perm_templ) VALUES '
            . "(1, 'Enforced', " . self::ENFORCING_GROUP_TEMPL . "), (2, 'Plain', " . self::PLAIN_GROUP_TEMPL . ')');

        $mfaRecords = $this->createStub(UserMfaRepositoryInterface::class);
        $mfaRecords->method('findByUserId')->willReturn(null);
        $this->service = new MfaService(
            $mfaRecords,
            new FakeConfiguration(['security' => ['mfa.enabled' => true, 'mfa.enforced' => true]]),
            $this->createStub(MfaVerificationMailerInterface::class),
            new Google2FaTotp(),
            new BaconQrCodeRenderer()
        );
    }

    private function addUser(int $id, int $templ, ?int $groupId = null): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES ($id, 'u$id', $templ)");
        if ($groupId !== null) {
            $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES ($groupId, $id)");
        }
    }

    public function testTheUsersOwnTemplateEnforcesMfa(): void
    {
        $this->addUser(1, self::ENFORCING_TEMPL);

        $this->assertTrue($this->service->isMfaEnforced(1, $this->db, 'sql'));
        $this->assertTrue($this->service->isMfaSetupRequired(1, $this->db, 'sql'));
    }

    public function testAGroupTemplateEnforcesMfa(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, groupId: 1);

        $this->assertTrue($this->service->isMfaEnforced(1, $this->db, 'sql'));
        $this->assertTrue($this->service->isMfaSetupRequired(1, $this->db, 'sql'));
    }

    public function testNoGrantOnTheUserOrTheirGroupsLeavesMfaOptional(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, groupId: 2);
        $this->addUser(2, self::PLAIN_TEMPL);

        $this->assertFalse($this->service->isMfaEnforced(1, $this->db, 'sql'));
        $this->assertFalse($this->service->isMfaEnforced(2, $this->db, 'sql'));
    }

    public function testAnotherUsersGrantDoesNotApply(): void
    {
        $this->addUser(1, self::ENFORCING_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, groupId: 1);
        $this->addUser(3, self::PLAIN_TEMPL);

        $this->assertFalse($this->service->isMfaEnforced(3, $this->db, 'sql'));
        $this->assertFalse($this->service->isMfaSetupRequired(3, $this->db, 'sql'));
    }
}
