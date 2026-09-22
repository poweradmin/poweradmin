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
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Domain\ValueObject\SamlUserInfo;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Infrastructure\Repository\DbExternalIdentityRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Pins the OIDC and SAML provisioning behaviour against the shipped SQLite
 * schema: what a first login creates, what a repeat login leaves alone, how
 * email linking, group revocation and orphaned link cleanup behave.
 */
class ExternalUserProvisioningIntegrationTest extends TestCase
{
    private const PROVIDER = 'okta';
    private const EDITOR_TEMPLATE_ID = 3;
    private const GUEST_TEMPLATE_ID = 5;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(file_get_contents(__DIR__ . '/../../sql/poweradmin-sqlite-db-structure.sql'));
    }

    public function testOidcLoginTwiceCreatesOneUserOneLinkAndStableGroups(): void
    {
        $svc = $this->service();
        $info = $this->oidcUser(['dns-editors']);

        $firstId = $svc->provisionUser($info, self::PROVIDER);
        $secondId = $svc->provisionUser($info, self::PROVIDER);

        $this->assertNotNull($firstId);
        $this->assertSame($firstId, $secondId, 'A repeat login resolves the same account by subject');
        $this->assertSame(1, $this->rowCount('users'), 'one users row');
        $this->assertSame(1, $this->rowCount('oidc_user_links'), 'one link row');
        $this->assertSame(0, $this->rowCount('saml_user_links'));

        $row = $this->userRow('jane.doe');
        $this->assertSame('Jane Doe', $row['fullname']);
        $this->assertSame('jane@example.org', $row['email']);
        $this->assertSame('', $row['password']);
        $this->assertSame('Created via OIDC from okta', $row['description']);
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$row['perm_templ']);
        $this->assertSame('oidc', $row['perm_templ_source']);
        $this->assertSame('oidc', $row['auth_method']);
        $this->assertSame(0, (int)$row['use_ldap']);
        $this->assertSame(1, (int)$row['active']);

        $link = $this->linkRow('oidc_user_links', $firstId);
        $this->assertSame('oidc|jane', $link['oidc_subject']);
        $this->assertSame('jane.doe', $link['username']);
        $this->assertSame('jane@example.org', $link['email']);

        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($firstId));
        $this->assertSame(2, $this->rowCount('user_group_members'), 'membership rows are not duplicated on re-login');
        $this->assertSame('jane.doe', $svc->getDatabaseUsername($firstId));
    }

    public function testSamlLoginTwiceCreatesOneUserOneLinkAndStableGroups(): void
    {
        $svc = $this->service();
        $info = $this->samlUser(['dns-editors']);

        $firstId = $svc->provisionUser($info, self::PROVIDER);
        $secondId = $svc->provisionUser($info, self::PROVIDER);

        $this->assertNotNull($firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('saml_user_links'));
        $this->assertSame(0, $this->rowCount('oidc_user_links'));

        $row = $this->userRow('john.roe');
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$row['perm_templ']);
        $this->assertSame('saml', $row['perm_templ_source']);
        $this->assertSame('saml', $row['auth_method']);
        $this->assertSame('Created via SAML from okta', $row['description']);

        $link = $this->linkRow('saml_user_links', $firstId);
        $this->assertSame('saml|john', $link['saml_subject']);
        $this->assertSame('john.roe', $link['username']);

        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($firstId));
        $this->assertSame(2, $this->rowCount('user_group_members'));
    }

    public function testRepeatLoginSyncsChangedNameEmailAndSubjectLink(): void
    {
        $svc = $this->service();
        $userId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);

        $svc->provisionUser($this->oidcUser(['dns-editors'], displayName: 'Jane Renamed', email: 'jane2@example.org'), self::PROVIDER);

        $row = $this->userRow('jane.doe');
        $this->assertSame('Jane Renamed', $row['fullname']);
        $this->assertSame('jane2@example.org', $row['email']);
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('oidc_user_links'));
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$this->userRow('jane.doe')['perm_templ']);
        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($userId));
    }

    public function testLeavingTheMappedGroupRevokesTemplateAndMemberships(): void
    {
        $svc = $this->service();
        $userId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);
        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($userId));

        $svc->provisionUser($this->oidcUser(['unrelated']), self::PROVIDER);

        $row = $this->userRow('jane.doe');
        $this->assertSame(self::GUEST_TEMPLATE_ID, (int)$row['perm_templ'], 'falls back to the default template');
        $this->assertSame('oidc', $row['perm_templ_source']);
        $this->assertSame([], $this->groupNames($userId));
        $this->assertSame(0, $this->rowCount('user_group_members'));
    }

    public function testAdminAssignedTemplateIsKeptWhenNoGroupMatches(): void
    {
        $userId = $this->insertLocalUser('jane.doe', 'jane@example.org', 'sql', self::EDITOR_TEMPLATE_ID, 'admin');
        $this->linkOidc($userId, 'oidc|jane');

        $this->service()->provisionUser($this->oidcUser(['unrelated']), self::PROVIDER);

        $row = $this->userRow('jane.doe');
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$row['perm_templ']);
        $this->assertSame('admin', $row['perm_templ_source']);
        $this->assertSame('sql', $row['auth_method'], 'a SQL account keeps its login method');
    }

    public function testEmailLinksAnExistingActiveAccountInsteadOfCreatingOne(): void
    {
        $userId = $this->insertLocalUser('local.jane', 'jane@example.org', 'sql', self::GUEST_TEMPLATE_ID, 'admin');

        $svc = $this->service();
        $resolved = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);
        $again = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);

        $this->assertSame($userId, $resolved, 'linked by email');
        $this->assertSame($userId, $again, 'then found by subject');
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('oidc_user_links'));
        $link = $this->linkRow('oidc_user_links', $userId);
        $this->assertSame('oidc|jane', $link['oidc_subject']);
        $this->assertSame('jane.doe', $link['username']);

        $row = $this->userRow('local.jane');
        $this->assertSame('sql', $row['auth_method']);
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$row['perm_templ']);
        $this->assertSame('oidc', $row['perm_templ_source']);
        $this->assertSame('local.jane', $svc->getDatabaseUsername($userId));
    }

    public function testEmailLinkingSkipsInactiveAndSuperuserAccounts(): void
    {
        $this->insertLocalUser('inactive.jane', 'jane@example.org', 'sql', self::GUEST_TEMPLATE_ID, 'admin', active: 0);
        $svc = $this->service();
        $userId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);
        $this->assertSame(2, $this->rowCount('users'), 'an inactive account is not linked, a new one is created');
        $this->assertSame('jane.doe', $this->userRow('jane.doe')['username']);
        $this->assertNotSame('inactive.jane', $svc->getDatabaseUsername($userId));

        $this->db->exec('DELETE FROM oidc_user_links');
        $this->db->exec('DELETE FROM users');
        $adminId = $this->insertLocalUser('root', 'root@example.org', 'sql', 1, 'admin');
        $samlId = $svc->provisionUser($this->samlUser(['dns-editors'], email: 'root@example.org'), self::PROVIDER);
        $this->assertNotSame($adminId, $samlId, 'an email claim never links to a superuser');
        $this->assertSame(0, $this->countWhere('saml_user_links', 'user_id', $adminId));
    }

    public function testUnverifiedOidcEmailIsNotLinked(): void
    {
        $this->insertLocalUser('local.jane', 'jane@example.org', 'sql', self::GUEST_TEMPLATE_ID, 'admin');

        $userId = $this->service()->provisionUser(
            $this->oidcUser(['dns-editors'], rawData: ['email_verified' => false]),
            self::PROVIDER
        );

        $this->assertSame(2, $this->rowCount('users'));
        $this->assertSame('jane.doe', $this->service()->getDatabaseUsername($userId));
    }

    public function testCollidingUsernameGetsANumericSuffix(): void
    {
        $this->insertLocalUser('jane.doe', 'other@example.org', 'sql', self::GUEST_TEMPLATE_ID, 'admin');
        $this->insertLocalUser('jane.doe_1', 'other1@example.org', 'sql', self::GUEST_TEMPLATE_ID, 'admin');

        $userId = $this->service()->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);

        $this->assertSame('jane.doe_2', $this->service()->getDatabaseUsername($userId));
        $this->assertSame('jane.doe', $this->linkRow('oidc_user_links', $userId)['username']);
    }

    public function testAutoProvisionDisabledReturnsNullWithoutWriting(): void
    {
        $svc = $this->service(['auto_provision' => false]);

        $this->assertNull($svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER));
        $this->assertSame(0, $this->rowCount('users'));
        $this->assertSame(0, $this->rowCount('oidc_user_links'));
    }

    public function testMissingDefaultTemplateRefusesToProvision(): void
    {
        $svc = $this->service(['default_permission_template' => 'Nope']);

        $this->assertNull($svc->provisionUser($this->oidcUser(['unrelated']), self::PROVIDER));
        $this->assertSame(0, $this->rowCount('users'));
    }

    public function testSuperuserTemplateAndGroupAreRefusedUnlessAllowed(): void
    {
        $config = [
            'permission_template_mapping' => ['dns-admins' => 'Administrator'],
            'group_mapping' => ['dns-admins' => ['Administrators', 'Viewers']],
        ];

        $userId = $this->service($config)->provisionUser($this->oidcUser(['dns-admins']), self::PROVIDER);
        $this->assertSame(self::GUEST_TEMPLATE_ID, (int)$this->userRow('jane.doe')['perm_templ']);
        $this->assertSame(['Viewers'], $this->groupNames($userId));

        $this->db->exec('DELETE FROM user_group_members');
        $this->db->exec('DELETE FROM oidc_user_links');
        $this->db->exec('DELETE FROM users');

        $userId = $this->service($config + ['allow_superuser_provisioning' => true])
            ->provisionUser($this->oidcUser(['dns-admins']), self::PROVIDER);
        $this->assertSame(1, (int)$this->userRow('jane.doe')['perm_templ']);
        $this->assertSame(['Administrators', 'Viewers'], $this->groupNames($userId));
    }

    public function testOidcToSamlSwitchUpdatesAuthMethodAndKeepsBothLinks(): void
    {
        $svc = $this->service();
        $userId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);

        $samlId = $svc->provisionUser($this->samlUser(['dns-editors'], username: 'jane.doe', email: 'jane@example.org'), self::PROVIDER);

        $this->assertSame($userId, $samlId, 'linked by email to the OIDC-created account');
        $this->assertSame('saml', $this->userRow('jane.doe')['auth_method']);
        $this->assertSame('saml', $this->userRow('jane.doe')['perm_templ_source']);
        $this->assertSame(1, $this->rowCount('oidc_user_links'));
        $this->assertSame(1, $this->rowCount('saml_user_links'));
    }

    public function testSyncExistingUserUsesTheAuthMethodOfTheUserInfo(): void
    {
        $userId = $this->insertLocalUser('john.roe', 'old@example.org', 'saml', self::GUEST_TEMPLATE_ID, 'saml');

        $this->service()->syncExistingUser($userId, $this->samlUser(['dns-editors']));

        $row = $this->userRow('john.roe');
        $this->assertSame('John Roe', $row['fullname']);
        $this->assertSame('john@example.org', $row['email']);
        $this->assertSame(self::EDITOR_TEMPLATE_ID, (int)$row['perm_templ']);
        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($userId));
        $this->assertSame(0, $this->rowCount('saml_user_links'), 'sync never writes a link row');
    }

    public function testOrphanedSubjectLinkIsPrunedAndTheUserRecreated(): void
    {
        $svc = $this->service();
        $oldId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);
        $this->db->exec('DELETE FROM user_group_members');
        $this->db->exec("DELETE FROM users WHERE id = $oldId");
        $this->assertSame(1, $this->rowCount('oidc_user_links'), 'sqlite leaves the orphan without PRAGMA foreign_keys');
        $orphanLinkId = (int)$this->db->query('SELECT id FROM oidc_user_links')->fetchColumn();

        $newId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);

        $this->assertNotNull($newId);
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('oidc_user_links'));
        $link = $this->linkRow('oidc_user_links', $newId);
        $this->assertNotSame($orphanLinkId, (int)$link['id'], 'the orphan was deleted and a fresh link written');
        $this->assertSame(['Editors', 'Viewers'], $this->groupNames($newId));

        $samlOld = $svc->provisionUser($this->samlUser(['dns-editors']), self::PROVIDER);
        $this->db->exec("DELETE FROM users WHERE id = $samlOld");
        $orphanLinkId = (int)$this->db->query('SELECT id FROM saml_user_links')->fetchColumn();
        $samlNew = $svc->provisionUser($this->samlUser(['dns-editors']), self::PROVIDER);
        $this->assertNotNull($samlNew);
        $this->assertSame(1, $this->rowCount('saml_user_links'));
        $this->assertNotSame($orphanLinkId, (int)$this->linkRow('saml_user_links', $samlNew)['id']);
    }

    public function testCleanupOrphanedAuthLinksReportsAndDeletesOidcOrphans(): void
    {
        $svc = $this->service();
        $liveId = $svc->provisionUser($this->oidcUser(['dns-editors']), self::PROVIDER);
        $deadId = $svc->provisionUser($this->oidcUser([], username: 'gone', subject: 'oidc|gone', email: 'gone@example.org'), self::PROVIDER);
        $this->db->exec("DELETE FROM users WHERE id = $deadId");

        $result = $svc->cleanupOrphanedAuthLinks();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['cleaned_up_count']);
        $this->assertCount(1, $result['orphaned_links']);
        $this->assertSame($deadId, (int)$result['orphaned_links'][0]['user_id']);
        $this->assertSame(self::PROVIDER, $result['orphaned_links'][0]['provider_id']);
        $this->assertSame('gone', $result['orphaned_links'][0]['username']);
        $this->assertArrayHasKey('id', $result['orphaned_links'][0]);
        $this->assertSame(1, $this->countWhere('oidc_user_links', 'user_id', $liveId));
        $this->assertSame(1, $this->rowCount('oidc_user_links'));

        $this->assertSame(
            ['success' => true, 'cleaned_up_count' => 0, 'orphaned_links' => []],
            $svc->cleanupOrphanedAuthLinks()
        );
    }

    // --- Service + config wiring -------------------------------------------

    /** @param array<string, mixed> $overrides applied to both the oidc and saml sections */
    private function service(array $overrides = []): UserProvisioningService
    {
        $config = $this->configManager($overrides);

        return new UserProvisioningService(
            $config,
            new NullLogger(),
            new DbUserRepository($this->db, $config, false),
            new DbExternalIdentityRepository($this->db),
            new DbUserGroupRepository($this->db),
            new DbUserGroupMemberRepository($this->db)
        );
    }

    private function configManager(array $overrides): FakeConfiguration
    {
        $section = array_merge([
            'sync_user_info' => true,
            'auto_provision' => true,
            'link_by_email' => true,
            'default_permission_template' => 'Guest',
            'permission_template_mapping' => ['dns-editors' => 'Editor'],
            'group_mapping' => ['dns-editors' => ['Editors', 'Viewers']],
        ], $overrides);

        return new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'oidc' => $section,
            'saml' => $section,
        ]);
    }

    private function oidcUser(
        array $groups,
        string $username = 'jane.doe',
        string $email = 'jane@example.org',
        string $displayName = 'Jane Doe',
        string $subject = 'oidc|jane',
        array $rawData = []
    ): UserInfoInterface {
        return new OidcUserInfo(
            username: $username,
            email: $email,
            firstName: 'Jane',
            lastName: 'Doe',
            displayName: $displayName,
            groups: $groups,
            providerId: self::PROVIDER,
            subject: $subject,
            rawData: $rawData
        );
    }

    private function samlUser(array $groups, string $username = 'john.roe', string $email = 'john@example.org'): UserInfoInterface
    {
        return new SamlUserInfo(
            username: $username,
            email: $email,
            firstName: 'John',
            lastName: 'Roe',
            displayName: 'John Roe',
            groups: $groups,
            providerId: self::PROVIDER,
            nameId: 'saml|john',
            sessionIndex: 'sess-1',
            rawAttributes: []
        );
    }

    // --- Database helpers --------------------------------------------------

    private function insertLocalUser(string $username, string $email, string $authMethod, int $permTempl, string $source, int $active = 1): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
             VALUES (?, 'hash', 'Local Person', ?, '', ?, ?, ?, 0, ?)"
        );
        $stmt->execute([$username, $email, $permTempl, $source, $active, $authMethod]);

        return (int)$this->db->lastInsertId();
    }

    private function linkOidc(int $userId, string $subject): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO oidc_user_links (user_id, provider_id, oidc_subject, username, email) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, self::PROVIDER, $subject, 'jane.doe', 'jane@example.org']);
    }

    /** @return array<string, scalar|null> */
    private function userRow(string $username): array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "User $username should exist");

        return $row;
    }

    /** @return array<string, scalar|null> */
    private function linkRow(string $table, int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM $table WHERE user_id = ? AND provider_id = ?");
        $stmt->execute([$userId, self::PROVIDER]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "$table row for user $userId should exist");

        return $row;
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

    private function rowCount(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    private function countWhere(string $table, string $column, int $value): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$value]);

        return (int)$stmt->fetchColumn();
    }
}
