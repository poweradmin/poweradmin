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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\LoggerInterface;

/** Expired reset rows keep counting toward the rate limits, so the cleanup must not shrink the throttle window. */
class PasswordResetThrottleWindowTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;
    private DbPasswordResetTokenRepository $repo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used INTEGER NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL
        )');

        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null) => match (true) {
                $group === 'database' && $key === 'type' => 'sqlite',
                $key === 'password_reset.token_lifetime' => 900,
                $key === 'password_reset.rate_limit_window' => 3600,
                $key === 'password_reset.enabled' => true,
                default => $default,
            }
        );
        $this->repo = new DbPasswordResetTokenRepository($this->db, $this->config);
    }

    private function addRow(string $token, int $createdSecondsAgo, int $lifetime = 900): void
    {
        $stmt = $this->db->prepare('INSERT INTO password_reset_tokens (email, token, created_at, expires_at) VALUES (:e, :t, :c, :x)');
        $created = time() - $createdSecondsAgo;
        $stmt->execute([':e' => 'user@example.com', ':t' => $token, ':c' => gmdate('Y-m-d H:i:s', $created), ':x' => gmdate('Y-m-d H:i:s', $created + $lifetime)]);
    }

    private function remaining(): array
    {
        return $this->db->query('SELECT token FROM password_reset_tokens ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testExpiredRowsStayUntilTheyLeaveTheRetentionWindow(): void
    {
        $this->addRow('recent', 1800);
        $this->addRow('old', 7200);

        $this->assertSame(1, $this->repo->deleteExpired(3600));
        $this->assertSame(['recent'], $this->remaining());
    }

    public function testWithoutRetentionExpiredRowsAreRemovedAsBefore(): void
    {
        $this->addRow('recent', 1800);
        $this->addRow('live', 60);

        $this->assertSame(1, $this->repo->deleteExpired());
        $this->assertSame(['live'], $this->remaining());
    }

    public function testServiceRetainsForTheLongerOfLifetimeAndWindow(): void
    {
        $this->addRow('recent', 1800);
        $this->addRow('old', 7200);

        $service = new PasswordResetService(
            $this->repo,
            $this->createMock(UserRepository::class),
            $this->createMock(MailService::class),
            $this->config,
            $this->createMock(UserAuthenticationService::class),
            $this->createMock(IpAddressRetriever::class),
            $this->createMock(LoggerInterface::class)
        );
        $service->cleanupExpiredTokens();

        $this->assertSame(['recent'], $this->remaining());
    }

    private function makeService(?UserRepository $users = null): PasswordResetService
    {
        return new PasswordResetService(
            $this->repo,
            $users ?? $this->createMock(UserRepository::class),
            $this->createMock(MailService::class),
            $this->config,
            $this->createMock(UserAuthenticationService::class),
            $this->createMock(IpAddressRetriever::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function rateLimitAllows(PasswordResetService $service): bool
    {
        $method = new \ReflectionMethod($service, 'checkRateLimit');

        return $method->invoke($service, 'user@example.com', '203.0.113.1');
    }

    #[RunInSeparateProcess]
    public function testMinimumSpacingIgnoresThePhpTimeZone(): void
    {
        // Parsing the DB-written created_at in PHP's zone read it hours in the future here
        date_default_timezone_set('Etc/GMT+8');
        $this->addRow('old-enough', 600);

        $this->assertTrue($this->rateLimitAllows($this->makeService()));
    }

    public function testMinimumSpacingStillBlocksARequestMadeJustNow(): void
    {
        $this->addRow('just-now', 10);

        $this->assertFalse($this->rateLimitAllows($this->makeService()));
    }

    #[RunInSeparateProcess]
    public function testTokenExpiryIsReadAsUtcWhateverThePhpTimeZone(): void
    {
        date_default_timezone_set('Etc/GMT-8');
        $users = $this->createMock(UserRepository::class);
        $users->method('countUsersByEmail')->willReturn(1);
        $users->method('getUserByEmail')->willReturn(['id' => 7, 'email' => 'user@example.com', 'auth_method' => 'sql']);
        $this->addRow('fresh-token', 60);

        $result = $this->makeService($users)->validateToken('fresh-token');

        $this->assertNotNull($result);
        $this->assertSame(7, $result['user']['id']);
    }
}
