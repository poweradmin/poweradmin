<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\UsernameRecoveryService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\NullLogger;

/**
 * Email lookups ignore case on every database, as MySQL's collation already does,
 * so the per-address throttles and recovery mail see one address however it is typed.
 */
class EmailCaseLookupTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT,
            email TEXT NOT NULL DEFAULT '', perm_templ INTEGER, active INTEGER NOT NULL DEFAULT 1, auth_method TEXT DEFAULT 'sql')");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT, template_type TEXT)");
        $this->db->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("CREATE TABLE username_recovery_requests (id INTEGER PRIMARY KEY, email TEXT NOT NULL,
            ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("INSERT INTO users (id, username, email) VALUES (1, 'alice', 'alice@example.com'), (2, 'bob', 'bob@example.com')");

        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturnCallback(fn($group, $key, $default = null) => match ($key) {
            'type' => 'sqlite',
            'username_recovery.enabled' => true,
            default => $default,
        });
    }

    public function testGetUserByEmailIgnoresCase(): void
    {
        $repository = new DbUserRepository($this->db, $this->config);

        $this->assertSame(1, (int)$repository->getUserByEmail('Alice@Example.COM')['id']);
        $this->assertNull($repository->getUserByEmail('alicia@example.com'));
    }

    public function testCountUsersByEmailCountsCaseVariants(): void
    {
        $this->db->exec("INSERT INTO users (id, username, email) VALUES (3, 'alice2', 'ALICE@example.com')");
        $repository = new DbUserRepository($this->db, $this->config);

        $this->assertSame(2, $repository->countUsersByEmail('alice@example.com'));
    }

    public function testResetThrottleCountsCaseVariants(): void
    {
        $this->db->exec("INSERT INTO password_reset_tokens (email) VALUES ('alice@example.com')");
        $repository = new DbPasswordResetTokenRepository($this->db, $this->config);

        $this->assertSame(1, $repository->countRecentAttempts('ALICE@example.com', 3600));
    }

    public function testRecoveryThrottleCountsCaseVariants(): void
    {
        $this->db->exec("INSERT INTO username_recovery_requests (email, ip_address) VALUES ('alice@example.com', '192.0.2.1')");
        $repository = new DbUsernameRecoveryRepository($this->db, $this->config);

        $this->assertSame(1, $repository->countRecentAttempts('ALICE@example.com', 3600));
    }

    public function testRecoveryMailsEachStoredAddressOnlyItsOwnAccounts(): void
    {
        $this->db->exec("INSERT INTO users (id, username, email) VALUES (3, 'other', 'ALICE@example.com')");
        $sent = [];
        $mail = $this->createMock(MailService::class);
        $mail->method('sendMail')->willReturnCallback(function ($to, $subject, $html, $text) use (&$sent) {
            $sent[$to] = $text;
            return true;
        });
        $ip = $this->createMock(IpAddressRetriever::class);
        $ip->method('getClientIp')->willReturn('192.0.2.1');
        $service = new UsernameRecoveryService(
            new DbUsernameRecoveryRepository($this->db, $this->config),
            new DbUserRepository($this->db, $this->config),
            $mail,
            $this->config,
            $ip,
            new NullLogger(),
            $this->db
        );

        $this->assertTrue($service->createRecoveryRequest('Alice@Example.com'));

        $this->assertSame(['alice@example.com', 'ALICE@example.com'], array_keys($sent));
        $this->assertStringContainsString('alice', $sent['alice@example.com']);
        $this->assertStringNotContainsString('other', $sent['alice@example.com']);
        $this->assertStringContainsString('other', $sent['ALICE@example.com']);
    }
}
