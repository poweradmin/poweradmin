<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;

/**
 * A template the provider assigned must not stay in force when its group mapping no longer
 * matches and the fallback cannot be applied: that sign-in is refused instead.
 */
class UserProvisioningServiceTemplateRevocationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL,
            fullname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, description VARCHAR(1024) NOT NULL, perm_templ integer NOT NULL,
            perm_templ_source VARCHAR(20) NOT NULL DEFAULT 'admin', active integer(1), use_ldap integer(1) NOT NULL,
            auth_method VARCHAR(20) NOT NULL DEFAULT 'sql')");
        $this->db->exec("CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, template_type VARCHAR(10) NOT NULL DEFAULT 'user')");
        $this->db->exec("CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id integer PRIMARY KEY, name VARCHAR(255) NOT NULL, perm_templ integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_group_members (id integer PRIMARY KEY, group_id integer NOT NULL, user_id integer NOT NULL)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            provider_id VARCHAR(50) NOT NULL, oidc_subject VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (5, 'Viewer'), (7, 'Admins')");
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
            VALUES (1, 'john', '', 'John', 'john@example.org', '', 7, 'sso', 1, 0, 'oidc')");
        $this->db->exec("INSERT INTO oidc_user_links (user_id, provider_id, oidc_subject, username, email) VALUES (1, 'okta', 'sub-john', 'john', 'john@example.org')");
    }

    private function signIn(?string $defaultTemplate, array $mapping = ['admins-group' => 'Admins'], bool $cleanupFails = false, array $groupMapping = [], bool $newUser = false): ?int
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('getGroup')->willReturn(['sync_user_info' => false]);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, mixed $default = null) use ($defaultTemplate, $mapping, $cleanupFails, $groupMapping): mixed {
                if ($key === 'group_mapping' && $cleanupFails) {
                    throw new \RuntimeException('group sync failed');
                }

                return match ($key) {
                    'default_permission_template' => $defaultTemplate ?? $default,
                    'permission_template_mapping' => $mapping,
                    'group_mapping' => $groupMapping,
                    default => $default,
                };
            }
        );
        $service = new UserProvisioningService($this->db, $config, $this->createMock(Logger::class));
        $userInfo = new OidcUserInfo(username: 'john', email: 'john@example.org', providerId: 'okta', subject: 'sub-john', rawData: ['email_verified' => true]);
        if ($newUser) {
            $userInfo = new OidcUserInfo(username: 'newbie', email: 'newbie@example.org', providerId: 'okta', subject: 'sub-newbie', rawData: ['email_verified' => true]);
        }

        return $service->provisionUser($userInfo, 'okta');
    }

    private function template(): int
    {
        return (int)$this->db->query('SELECT perm_templ FROM users WHERE id = 1')->fetchColumn();
    }

    public function testRevocationFallsBackToTheDefaultTemplate(): void
    {
        $this->assertSame(1, $this->signIn('Viewer'));
        $this->assertSame(5, $this->template());
    }

    public function testADuplicateNamedGroupTemplateDoesNotWinTheNameLookup(): void
    {
        $this->db->exec("INSERT INTO perm_templ (id, name, template_type) VALUES (3, 'Viewer', 'group')");

        $this->assertSame(1, $this->signIn('Viewer'));
        $this->assertSame(5, $this->template());
    }

    public function testADuplicateNamedUserTemplateWithAHigherIdDoesNotWinTheNameLookup(): void
    {
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (9, 'Viewer')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (6, 'Viewer')");

        $this->assertSame(1, $this->signIn('Viewer'));
        $this->assertSame(5, $this->template());
    }

    public function testSignInIsRefusedWhenNoDefaultTemplateIsConfigured(): void
    {
        $this->assertNull($this->signIn(null));
        $this->assertSame(7, $this->template());
    }

    public function testSignInIsRefusedWhenTheDefaultTemplateDoesNotExist(): void
    {
        $this->assertNull($this->signIn('Gone'));
    }

    public function testSignInIsRefusedWhenTheRevokeWriteFails(): void
    {
        $this->db->exec("CREATE TRIGGER block_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'write failed'); END");

        $this->assertNull($this->signIn('Viewer'));
        $this->assertSame(7, $this->template());
    }

    public function testAnUnresolvableDefaultAllowsATemplateTheMappingDoesNotGrant(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 5");

        $this->assertSame(1, $this->signIn(null));
        $this->assertSame(5, $this->template());
    }

    public function testAGroupSyncFailureAfterTheRevokeWasWrittenRefusesTheSignIn(): void
    {
        $this->assertNull($this->signIn('Viewer', ['admins-group' => 'Admins'], true));
        $this->assertSame(5, $this->template());
    }

    public function testAGroupSyncFailureRefusesTheFirstSignInOfANewUser(): void
    {
        $this->assertNull($this->signIn('Viewer', [], true, [], true));
    }

    public function testANewUserIsCreatedWhenTheGroupSyncSucceeds(): void
    {
        $this->assertNotNull($this->signIn('Viewer', [], false, [], true));
    }

    public function testAFailedGroupRemovalRefusesTheSignIn(): void
    {
        $this->db->exec("UPDATE users SET perm_templ_source = 'admin'");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (3, 'Team', 5)");
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (3, 1)");
        $this->db->exec("CREATE TRIGGER block_delete BEFORE DELETE ON user_group_members BEGIN SELECT RAISE(ABORT, 'delete failed'); END");

        $this->assertNull($this->signIn(null, [], false, ['team-group' => 'Team']));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM user_group_members')->fetchColumn());
    }

    public function testASuccessfulGroupRemovalAllowsTheSignIn(): void
    {
        $this->db->exec("UPDATE users SET perm_templ_source = 'admin'");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (3, 'Team', 5)");
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (3, 1)");

        $this->assertSame(1, $this->signIn(null, [], false, ['team-group' => 'Team']));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_group_members')->fetchColumn());
    }

    public function testASuperuserTemplateTheGrantPathWouldRefuseIsStillTreatedAsMapped(): void
    {
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (9, 'Administrator')");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (9, 53)");
        $this->db->exec("UPDATE users SET perm_templ = 9");

        $this->assertNull($this->signIn(null, ['admins-group' => 'Administrator']));
        $this->assertSame(9, $this->template());
    }

    public function testALookupErrorDuringTheMappedCheckRefusesTheSignIn(): void
    {
        $this->db->exec('DROP TABLE perm_templ');

        $this->assertNull($this->signIn(null));
        $this->assertSame(7, $this->template());
    }

    public function testAnAdminAssignedTemplateIsLeftAloneWithoutADefault(): void
    {
        $this->db->exec("UPDATE users SET perm_templ_source = 'admin'");

        $this->assertSame(1, $this->signIn(null));
        $this->assertSame(7, $this->template());
    }
}
