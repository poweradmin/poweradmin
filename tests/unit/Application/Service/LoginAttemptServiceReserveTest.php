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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class LoginAttemptServiceReserveTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->db->exec("INSERT INTO users (id, username) VALUES (1, 'alice')");
        $this->db->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ip_address TEXT,'
            . ' timestamp INTEGER, successful INTEGER, attempt_type TEXT)');
    }

    private function service(int $mfaAttempts = 2): LoginAttemptService
    {
        $settings = [
            'security.mfa.max_verify_attempts' => $mfaAttempts,
            'security.mfa.verify_lockout_duration' => 15,
            'database.type' => 'sqlite',
        ];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $settings["$group.$key"] ?? $default
        );

        return new LoginAttemptService($this->db, $config);
    }

    private function rows(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    }

    public function testParallelGuessesAreCountedBeforeAnyIsChecked(): void
    {
        $service = $this->service(1);

        // Both requests pass the lockout check, then reserve before either verifies
        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.2', LoginAttemptService::STAGE_MFA, 1));
        $this->assertTrue($service->reserveAttempt('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $this->assertFalse($service->reserveAttempt('alice', '10.0.0.2', LoginAttemptService::STAGE_MFA, 1));
        $this->assertSame(2, $this->rows());
    }

    public function testReservationAllowsExactlyTheConfiguredAttempts(): void
    {
        $service = $this->service(2);

        $this->assertTrue($service->reserveAttempt('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $this->assertTrue($service->reserveAttempt('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $this->assertFalse($service->reserveAttempt('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
    }

    public function testACorrectCodeClearsItsReservedRow(): void
    {
        $service = $this->service(2);

        $this->assertTrue($service->reserveAttempt('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $service->recordAttempt('alice', '10.0.0.1', true, LoginAttemptService::STAGE_MFA, 1);

        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA, 1));
        $this->assertSame(1, $this->rows());
    }

    public function testReservingForAnUnknownAccountRefusesTheSecondFactorOnly(): void
    {
        $service = $this->service();

        $this->assertFalse($service->reserveAttempt('nobody', '10.0.0.1', LoginAttemptService::STAGE_MFA));
        $this->assertTrue($service->reserveAttempt('nobody', '10.0.0.1'));
    }
}
