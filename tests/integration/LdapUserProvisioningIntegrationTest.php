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
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\ValueObject\LdapUserInfo;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\NullLogHandler;
use ReflectionClass;

/**
 * Exercises the LDAP identity-sync, auto-provisioning and group-mapping paths
 * (v4.5.0) against a real LDAP directory and a real SQLite database.
 *
 * The test manages its own fixtures under a dedicated `itest-*` namespace and
 * skips when no LDAP server is reachable, matching how the database-backed
 * integration tests degrade. Group-mapping assertions additionally skip when
 * the directory has no `memberof` overlay (so `memberOf` is not populated).
 *
 * Connection is taken from the environment, defaulting to the devcontainer
 * OpenLDAP service:
 *   LDAP_TEST_URI       (ldap://localhost:389)
 *   LDAP_TEST_BASE_DN   (dc=poweradmin,dc=org)
 *   LDAP_TEST_BIND_DN   (cn=admin,dc=poweradmin,dc=org)
 *   LDAP_TEST_BIND_PW   (poweradmin)
 */
class LdapUserProvisioningIntegrationTest extends TestCase
{
    private const USERS_OU = 'ou=users';
    private const GROUPS_OU = 'ou=groups';
    private const SYNC_UID = 'itest-sync';
    private const PROVISION_UID = 'itest-provision';
    private const GROUP_CN = 'itest-admins';

    /** @var resource|\LDAP\Connection */
    private static $ldap;
    private static string $baseDn;
    private static bool $memberOfAvailable = false;

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('ldap')) {
            self::markTestSkipped('ext-ldap not loaded');
        }

        $uri = getenv('LDAP_TEST_URI') ?: 'ldap://localhost:389';
        self::$baseDn = getenv('LDAP_TEST_BASE_DN') ?: 'dc=poweradmin,dc=org';
        $bindDn = getenv('LDAP_TEST_BIND_DN') ?: 'cn=admin,dc=poweradmin,dc=org';
        $bindPw = getenv('LDAP_TEST_BIND_PW') ?: 'poweradmin';

        $conn = @ldap_connect($uri);
        if (!$conn) {
            self::markTestSkipped("Cannot init LDAP connection to $uri");
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 3);
        if (!@ldap_bind($conn, $bindDn, $bindPw)) {
            self::markTestSkipped("Cannot bind to LDAP at $uri as $bindDn");
        }

        self::$ldap = $conn;
        self::ensureOu(self::USERS_OU);
        self::ensureOu(self::GROUPS_OU);
        self::$memberOfAvailable = self::detectMemberOfOverlay();
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$ldap) || !self::$ldap) {
            return;
        }
        // Delete in dependency order (group references members).
        self::deleteDn(self::groupDn(self::GROUP_CN));
        self::deleteDn(self::userDn(self::SYNC_UID));
        self::deleteDn(self::userDn(self::PROVISION_UID));
    }

    protected function setUp(): void
    {
        // Fresh directory + database per test so the cases stay independent.
        self::deleteDn(self::groupDn(self::GROUP_CN));
        self::deleteDn(self::userDn(self::SYNC_UID));
        self::deleteDn(self::userDn(self::PROVISION_UID));

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(__DIR__ . '/../../sql/poweradmin-sqlite-db-structure.sql');
        $this->db->exec($schema);
    }

    public function testSyncOverwritesStaleIdentityFromDirectory(): void
    {
        $this->addUser(self::SYNC_UID, 'Synced Person', 'synced@example.org', 'sync-pass');
        // Stored row carries stale identity and an admin-assigned Viewer template.
        $userId = $this->insertLocalLdapUser(self::SYNC_UID, 'STALE Name', 'stale@old.example', 4);

        $svc = $this->service();
        $svc->syncExistingUser($userId, $this->userInfo(self::SYNC_UID));

        $row = $this->userRow(self::SYNC_UID);
        $this->assertSame('Synced Person', $row['fullname']);
        $this->assertSame('synced@example.org', $row['email']);
        $this->assertSame('4', (string)$row['perm_templ'], 'Template untouched: user is in no mapped group');
        $this->assertSame('admin', $row['perm_templ_source'], 'Admin-assigned template is preserved');
    }

    public function testSyncIsIdempotent(): void
    {
        $this->addUser(self::SYNC_UID, 'Synced Person', 'synced@example.org', 'sync-pass');
        $userId = $this->insertLocalLdapUser(self::SYNC_UID, 'x', 'x@x', 4);

        $svc = $this->service();
        $info = $this->userInfo(self::SYNC_UID);
        $svc->syncExistingUser($userId, $info);
        $first = $this->userRow(self::SYNC_UID);
        $svc->syncExistingUser($userId, $info);
        $svc->syncExistingUser($userId, $info);
        $this->assertSame($first, $this->userRow(self::SYNC_UID), 'Repeated syncs converge to a stable row');
    }

    public function testAutoProvisionCreatesUserWithMappedTemplateAndGroups(): void
    {
        if (!self::$memberOfAvailable) {
            $this->markTestSkipped('memberof overlay not enabled; cannot exercise group mapping');
        }
        $this->addUser(self::PROVISION_UID, 'Provisioned Admin', 'prov@example.org', 'prov-pass');
        $this->addGroup(self::GROUP_CN, [self::userDn(self::PROVISION_UID)]);

        $svc = $this->service();
        $userId = $svc->provisionUser($this->userInfo(self::PROVISION_UID), 'ldap');

        $this->assertNotNull($userId, 'Auto-provisioning creates the account');
        $row = $this->userRow(self::PROVISION_UID);
        $this->assertSame('Provisioned Admin', $row['fullname']);
        $this->assertSame('1', (string)$row['perm_templ'], 'group RDN maps to Administrator template');
        $this->assertSame('ldap', $row['perm_templ_source']);
        $this->assertSame('1', (string)$row['use_ldap']);
        $this->assertEqualsCanonicalizing(['Administrators', 'Editors'], $this->groupNames($userId), '1:n group_mapping applied');
    }

    public function testTemplateAndGroupsRevokedWhenUserLeavesMappedGroup(): void
    {
        if (!self::$memberOfAvailable) {
            $this->markTestSkipped('memberof overlay not enabled; cannot exercise group mapping');
        }
        $this->addUser(self::PROVISION_UID, 'Provisioned Admin', 'prov@example.org', 'prov-pass');
        $this->addGroup(self::GROUP_CN, [self::userDn(self::PROVISION_UID)]);

        $svc = $this->service();
        $userId = $svc->provisionUser($this->userInfo(self::PROVISION_UID), 'ldap');
        $this->assertSame('1', (string)$this->userRow(self::PROVISION_UID)['perm_templ']);

        // Drop the group; the next sync must revoke the mapped template + memberships.
        self::deleteDn(self::groupDn(self::GROUP_CN));
        $svc->syncExistingUser((int)$userId, $this->userInfo(self::PROVISION_UID));

        $row = $this->userRow(self::PROVISION_UID);
        $this->assertSame('5', (string)$row['perm_templ'], 'Falls back to the Guest default template');
        $this->assertSame([], $this->groupNames((int)$userId), 'Mapped memberships removed');
    }

    public function testAutoProvisionRefusedWhenUsernameIsLocalAccount(): void
    {
        $this->addUser(self::PROVISION_UID, 'Provisioned Admin', 'prov@example.org', 'prov-pass');
        // A local (non-LDAP) account already owns the username.
        $this->db->exec(
            "INSERT INTO users (username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
             VALUES ('" . self::PROVISION_UID . "', 'hash', 'Local', 'local@x', '', 1, 'admin', 1, 0, 'sql')"
        );

        $svc = $this->service();
        $userId = $svc->provisionUser($this->userInfo(self::PROVISION_UID), 'ldap');

        $this->assertNull($userId, 'Provisioning is refused rather than creating a suffixed duplicate');
        $count = $this->db->query("SELECT COUNT(*) FROM users WHERE username LIKE '" . self::PROVISION_UID . "%'")->fetchColumn();
        $this->assertSame('1', (string)$count, 'No duplicate row created');
    }

    // --- Service + config wiring -------------------------------------------

    private function service(): UserProvisioningService
    {
        return new UserProvisioningService($this->db, $this->configManager(), new Logger(new NullLogHandler(), 'error'));
    }

    private function configManager(): ConfigurationManager
    {
        $settings = [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'ldap' => [
                'sync_user_info' => true,
                'auto_provision' => true,
                'default_permission_template' => 'Guest',
                // Keyed by the group RDN (self::GROUP_CN); the directory returns the full DN.
                'permission_template_mapping' => [self::GROUP_CN => 'Administrator'],
                'group_mapping' => [self::GROUP_CN => ['Administrators', 'Editors']],
            ],
        ];

        $reflection = new ReflectionClass(ConfigurationManager::class);
        $config = $reflection->newInstanceWithoutConstructor();
        $settingsProp = $reflection->getProperty('settings');
        $settingsProp->setValue($config, $settings);
        $initProp = $reflection->getProperty('initialized');
        $initProp->setValue($config, true);

        return $config;
    }

    private function userInfo(string $uid): LdapUserInfo
    {
        $entry = $this->fetchEntry($uid);
        // 'dns-admins' is keyed by the group's RDN; the directory returns the full DN.
        return LdapUserInfo::fromLdapEntry($entry, $uid, 'displayName', 'mail', 'memberOf');
    }

    // --- Database helpers --------------------------------------------------

    private function insertLocalLdapUser(string $username, string $fullname, string $email, int $permTempl): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
             VALUES (?, 'LDAP_USER', ?, ?, 'ldap user', ?, 'admin', 1, 1, 'ldap')"
        );
        $stmt->execute([$username, $fullname, $email, $permTempl]);
        return (int)$this->db->lastInsertId();
    }

    private function userRow(string $username): array
    {
        $stmt = $this->db->prepare('SELECT fullname, email, perm_templ, perm_templ_source, use_ldap FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    private function groupNames(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ug.name FROM user_group_members m JOIN user_groups ug ON ug.id = m.group_id WHERE m.user_id = ? ORDER BY ug.name'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // --- LDAP fixture helpers ----------------------------------------------

    private static function userDn(string $uid): string
    {
        return "uid=$uid," . self::USERS_OU . ',' . self::$baseDn;
    }

    private static function groupDn(string $cn): string
    {
        return "cn=$cn," . self::GROUPS_OU . ',' . self::$baseDn;
    }

    private static function ensureOu(string $ou): void
    {
        @ldap_add(self::$ldap, $ou . ',' . self::$baseDn, [
            'objectClass' => 'organizationalUnit',
            'ou' => explode('=', $ou, 2)[1],
        ]);
    }

    private function addUser(string $uid, string $displayName, string $mail, string $password): void
    {
        ldap_add(self::$ldap, self::userDn($uid), [
            'objectClass' => ['inetOrgPerson'],
            'uid' => $uid,
            'cn' => $displayName,
            'sn' => $displayName,
            'displayName' => $displayName,
            'mail' => $mail,
            'userPassword' => $password,
        ]);
    }

    private function addGroup(string $cn, array $memberDns): void
    {
        ldap_add(self::$ldap, self::groupDn($cn), [
            'objectClass' => ['groupOfNames'],
            'cn' => $cn,
            'member' => $memberDns,
        ]);
    }

    private function fetchEntry(string $uid): array
    {
        $result = ldap_search(self::$ldap, self::USERS_OU . ',' . self::$baseDn, "(uid=$uid)", ['uid', 'dn', 'displayName', 'mail', 'memberOf']);
        $entries = ldap_get_entries(self::$ldap, $result);
        return $entries[0];
    }

    private static function detectMemberOfOverlay(): bool
    {
        // Probe: create a throwaway user + group, then check memberOf populates.
        $probeUser = self::userDn('itest-probe');
        $probeGroup = self::groupDn('itest-probe-grp');
        @ldap_add(self::$ldap, $probeUser, [
            'objectClass' => ['inetOrgPerson'],
            'uid' => 'itest-probe',
            'cn' => 'probe',
            'sn' => 'probe',
        ]);
        @ldap_add(self::$ldap, $probeGroup, [
            'objectClass' => ['groupOfNames'],
            'cn' => 'itest-probe-grp',
            'member' => [$probeUser],
        ]);
        $available = false;
        $result = @ldap_search(self::$ldap, self::USERS_OU . ',' . self::$baseDn, '(uid=itest-probe)', ['memberOf']);
        if ($result) {
            $entries = @ldap_get_entries(self::$ldap, $result);
            $available = isset($entries[0]['memberof']['count']) && $entries[0]['memberof']['count'] > 0;
        }
        self::deleteDn($probeGroup);
        self::deleteDn($probeUser);
        return $available;
    }

    private static function deleteDn(string $dn): void
    {
        @ldap_delete(self::$ldap, $dn);
    }
}
