<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Domain\ValueObject\SamlUserInfo;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;

/**
 * An email match must not re-point an account that is already linked to another
 * identity at the same provider; that would hand the account over and lock the owner out.
 */
class UserProvisioningServiceRelinkTest extends TestCase
{
    private PDO $db;
    private UserProvisioningService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL,
            fullname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, description VARCHAR(1024) NOT NULL, perm_templ integer NOT NULL,
            perm_templ_source VARCHAR(20) NOT NULL DEFAULT 'admin', active integer(1), use_ldap integer(1) NOT NULL,
            auth_method VARCHAR(20) NOT NULL DEFAULT 'sql')");
        $this->db->exec("CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id integer PRIMARY KEY, name VARCHAR(255) NOT NULL, perm_templ integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_group_members (id integer PRIMARY KEY, group_id integer NOT NULL, user_id integer NOT NULL)");
        foreach (['oidc', 'saml'] as $kind) {
            $this->db->exec("CREATE TABLE {$kind}_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                provider_id VARCHAR(50) NOT NULL, {$kind}_subject VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        }
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES
            (1, 'john', '', 'John', 'john@example.org', '', 5, 1, 0, 'oidc'),
            (2, 'jane', '', 'Jane', 'jane@example.org', '', 5, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO oidc_user_links (user_id, provider_id, oidc_subject, username, email) VALUES (1, 'okta', 'sub-owner', 'john', 'john@example.org')");
        $this->db->exec("INSERT INTO saml_user_links (user_id, provider_id, saml_subject, username, email) VALUES (1, 'adfs', 'name-owner', 'john', 'john@example.org')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('getGroup')->willReturn(['link_by_email' => true, 'auto_provision' => false]);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        $this->service = new UserProvisioningService($this->db, $config, $this->createMock(Logger::class));
    }

    public function testOidcEmailMatchDoesNotRelinkAnAccountLinkedToAnotherSubject(): void
    {
        $userInfo = new OidcUserInfo(username: 'mallory', email: 'john@example.org', providerId: 'okta', subject: 'sub-other', rawData: ['email_verified' => true]);

        $this->assertNull($this->service->provisionUser($userInfo, 'okta'));
        $this->assertSame('sub-owner', $this->db->query("SELECT oidc_subject FROM oidc_user_links WHERE user_id = 1")->fetchColumn());
    }

    public function testSamlEmailMatchDoesNotRelinkAnAccountLinkedToAnotherSubject(): void
    {
        $userInfo = new SamlUserInfo(username: 'mallory', email: 'john@example.org', providerId: 'adfs', nameId: 'name-other');

        $this->assertNull($this->service->provisionUser($userInfo, 'adfs'));
        $this->assertSame('name-owner', $this->db->query("SELECT saml_subject FROM saml_user_links WHERE user_id = 1")->fetchColumn());
    }

    public function testEmailMatchStillLinksAnAccountWithoutALinkAtTheProvider(): void
    {
        $userInfo = new OidcUserInfo(username: 'jane', email: 'jane@example.org', providerId: 'okta', subject: 'sub-jane', rawData: ['email_verified' => true]);

        $this->assertSame(2, $this->service->provisionUser($userInfo, 'okta'));
        $this->assertSame('sub-jane', $this->db->query("SELECT oidc_subject FROM oidc_user_links WHERE user_id = 2")->fetchColumn());
    }

    public function testALinkAtAnotherProviderDoesNotBlockEmailLinking(): void
    {
        $userInfo = new OidcUserInfo(username: 'john', email: 'john@example.org', providerId: 'google', subject: 'sub-google', rawData: ['email_verified' => true]);

        $this->assertSame(1, $this->service->provisionUser($userInfo, 'google'));
        $this->assertSame('sub-owner', $this->db->query("SELECT oidc_subject FROM oidc_user_links WHERE provider_id = 'okta'")->fetchColumn());
    }
}
