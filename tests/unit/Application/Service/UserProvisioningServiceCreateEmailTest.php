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
 * Provisioning a new account must not store an address another account already holds.
 */
class UserProvisioningServiceCreateEmailTest extends TestCase
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
        $this->db->exec("CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, template_type VARCHAR(10) NOT NULL DEFAULT 'user')");
        $this->db->exec("CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id integer PRIMARY KEY, name VARCHAR(255) NOT NULL, perm_templ integer NOT NULL)");
        $this->db->exec("CREATE TABLE user_group_members (id integer PRIMARY KEY, group_id integer NOT NULL, user_id integer NOT NULL)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            provider_id VARCHAR(50) NOT NULL, oidc_subject VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES
            (1, 'john', '', 'John', 'john@example.org', '', 5, 1, 0, 'oidc')");

        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (7, 'Users')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('getGroup')->willReturn(['link_by_email' => false, 'auto_provision' => true, 'sync_user_info' => true]);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $key === 'default_permission_template' ? 'Users' : $default
        );

        $this->service = new UserProvisioningService($this->db, $config, $this->createMock(Logger::class));
    }

    private function email(int $id): string
    {
        return (string) $this->db->query("SELECT email FROM users WHERE id = $id")->fetchColumn();
    }

    public function testNewAccountDoesNotStoreAnAddressAnotherAccountHolds(): void
    {
        $userInfo = new OidcUserInfo(username: 'newbie', email: 'JOHN@example.org', providerId: 'okta', subject: 'sub-new', rawData: []);

        $id = $this->service->provisionUser($userInfo, 'okta');

        $this->assertNotNull($id);
        $this->assertSame('', $this->email($id));
        $this->assertSame('john@example.org', $this->email(1));
    }

    public function testNewAccountStoresAFreeAddress(): void
    {
        $userInfo = new OidcUserInfo(username: 'newbie', email: 'new@example.org', providerId: 'okta', subject: 'sub-new', rawData: []);

        $id = $this->service->provisionUser($userInfo, 'okta');

        $this->assertNotNull($id);
        $this->assertSame('new@example.org', $this->email($id));
    }
}
