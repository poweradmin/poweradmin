<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionMethod;

/**
 * Basic Auth on the API must honour account_lockout like the browser login,
 * or it is an unthrottled password-guessing endpoint.
 */
class BasicAuthenticationMiddlewareLockoutTest extends TestCase
{
    private PDO $db;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL,
            active INTEGER, use_ldap INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL,
            ip_address VARCHAR(45) NOT NULL, timestamp INTEGER NOT NULL, successful BOOLEAN NOT NULL)");
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password, active, use_ldap) VALUES (7, 'alice', :hash, 1, 0)");
        $stmt->execute(['hash' => password_hash('right-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function middleware(bool $lockout): BasicAuthenticationMiddleware
    {
        $settings = [
            'security.password_encryption' => 'bcrypt',
            'security.password_cost' => 4,
            'security.account_lockout.enable_lockout' => $lockout,
            'security.account_lockout.lockout_attempts' => 3,
            'security.account_lockout.lockout_duration' => 15,
            'security.account_lockout.track_ip_address' => true,
            'security.account_lockout.clear_attempts_on_success' => true,
            'security.account_lockout.whitelist_ip_addresses' => [],
            'security.account_lockout.blacklist_ip_addresses' => [],
            'database.type' => 'sqlite',
            'ldap.enabled' => false,
        ];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $settings["$group.$key"] ?? $default
        );

        return new BasicAuthenticationMiddleware($this->db, $config);
    }

    private function authenticate(BasicAuthenticationMiddleware $middleware, string $password): int
    {
        return (new ReflectionMethod(BasicAuthenticationMiddleware::class, 'authenticateAndGetUserId'))
            ->invoke($middleware, 'alice', $password);
    }

    public function testCorrectPasswordIsRefusedOnceTheAccountIsLockedOut(): void
    {
        $middleware = $this->middleware(true);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $this->authenticate($middleware, 'guess-' . $i));
        }

        $this->assertSame(0, $this->authenticate($middleware, 'right-password'));
    }

    public function testCorrectPasswordWorksWithoutPriorFailures(): void
    {
        $this->assertSame(7, $this->authenticate($this->middleware(true), 'right-password'));
    }

    public function testNothingIsLockedWhileLockoutIsOff(): void
    {
        $middleware = $this->middleware(false);
        for ($i = 0; $i < 3; $i++) {
            $this->authenticate($middleware, 'guess-' . $i);
        }

        $this->assertSame(7, $this->authenticate($middleware, 'right-password'));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }
}
