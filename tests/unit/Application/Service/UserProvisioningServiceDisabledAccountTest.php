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
 * A disabled account must not be signed in through OIDC or SAML, nor replaced by a
 * freshly provisioned active one, nor have provider data written onto it.
 */
class UserProvisioningServiceDisabledAccountTest extends TestCase
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
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            provider_id VARCHAR(50) NOT NULL, oidc_subject VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES
            (1, 'active', '', 'Active', 'active@example.org', '', 5, 1, 0, 'sql'),
            (2, 'linked', '', 'Before', 'linked@example.org', '', 5, 0, 0, 'oidc'),
            (3, 'old.jane', 'h', 'Old Jane', 'jane@example.org', '', 5, 0, 0, 'sql'),
            (4, 'old.joe', 'h', 'Old Joe', 'joe@example.org', '', 5, NULL, 0, 'sql')");
        $this->db->exec("INSERT INTO oidc_user_links (user_id, provider_id, oidc_subject, username, email) VALUES (2, 'okta', 'sub-linked', 'linked', 'linked@example.org')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('getGroup')->willReturn(['link_by_email' => true, 'auto_provision' => true, 'sync_user_info' => true]);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        $this->service = new UserProvisioningService($this->db, $config, $this->createMock(Logger::class));
    }

    public function testIsActiveUserIsFalseForDisabledAndMissingAccounts(): void
    {
        $this->assertTrue($this->service->isActiveUser(1));
        $this->assertFalse($this->service->isActiveUser(2));
        $this->assertFalse($this->service->isActiveUser(99));
    }

    public function testLinkedDisabledAccountIsReturnedUntouched(): void
    {
        $userInfo = new OidcUserInfo(username: 'linked', email: 'linked@example.org', displayName: 'After', providerId: 'okta', subject: 'sub-linked');

        $this->assertSame(2, $this->service->provisionUser($userInfo, 'okta'));
        $this->assertSame('Before', $this->db->query('SELECT fullname FROM users WHERE id = 2')->fetchColumn(), 'a refused login writes no provider data');
    }

    public function testDisabledEmailMatchIsReturnedInsteadOfProvisioningANewAccount(): void
    {
        $userInfo = new OidcUserInfo(username: 'jane.doe', email: 'jane@example.org', providerId: 'okta', subject: 'sub-jane');

        $this->assertSame(3, $this->service->provisionUser($userInfo, 'okta'));
        $this->assertSame(4, (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no replacement account is created');
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM oidc_user_links')->fetchColumn(), 'and the disabled account is not linked');
    }

    /**
     * PostgreSQL allows users.active to be NULL, which every login check treats as disabled.
     */
    public function testNullActiveEmailMatchCountsAsDisabled(): void
    {
        $userInfo = new OidcUserInfo(username: 'joe', email: 'joe@example.org', providerId: 'okta', subject: 'sub-joe');

        $this->assertSame(4, $this->service->provisionUser($userInfo, 'okta'));
        $this->assertFalse($this->service->isActiveUser(4));
    }
}
