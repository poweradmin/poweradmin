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
use Poweradmin\Application\Service\UsernameRecoveryService;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\LoggerInterface;

/** The minimum spacing between recovery requests is measured by the database clock that stamped the row. */
class UsernameRecoverySpacingTest extends TestCase
{
    private PDO $db;
    private UsernameRecoveryService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE username_recovery_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        $this->service = new UsernameRecoveryService(
            new DbUsernameRecoveryRepository($this->db, $config),
            $this->createMock(UserRepository::class),
            $this->createMock(MailService::class),
            $config,
            $this->createMock(IpAddressRetriever::class),
            $this->createMock(LoggerInterface::class),
            $this->db
        );
    }

    private function addRow(int $secondsAgo): void
    {
        $stmt = $this->db->prepare('INSERT INTO username_recovery_requests (email, ip_address, created_at) VALUES (:e, :i, :c)');
        $stmt->execute([':e' => 'user@example.com', ':i' => '203.0.113.1', ':c' => gmdate('Y-m-d H:i:s', time() - $secondsAgo)]);
    }

    private function rateLimitAllows(): bool
    {
        $method = new \ReflectionMethod($this->service, 'checkRateLimit');

        return $method->invoke($this->service, 'user@example.com', '203.0.113.1');
    }

    #[RunInSeparateProcess]
    public function testSpacingIgnoresThePhpTimeZone(): void
    {
        date_default_timezone_set('Etc/GMT+8');
        $this->addRow(600);

        $this->assertTrue($this->rateLimitAllows());
    }

    public function testARequestMadeJustNowIsStillBlocked(): void
    {
        $this->addRow(10);

        $this->assertFalse($this->rateLimitAllows());
    }
}
