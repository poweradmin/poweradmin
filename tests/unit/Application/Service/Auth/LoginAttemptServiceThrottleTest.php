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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Infrastructure\Repository\DbLoginAttemptRepository;
use TestHelpers\FakeConfiguration;

/**
 * Pins the throttle window and the lockout counting against a real database, since
 * these queries decide whether a login is allowed through.
 */
#[CoversClass(LoginAttemptService::class)]
#[CoversClass(DbLoginAttemptRepository::class)]
class LoginAttemptServiceThrottleTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)",
                "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                    ip_address TEXT NOT NULL, timestamp INTEGER NOT NULL, successful INTEGER NOT NULL,
                    attempt_type TEXT)",
                "INSERT INTO users (id, username) VALUES (1, 'alice'), (2, 'bob')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    private function service(array $security): LoginAttemptService
    {
        $config = new FakeConfiguration(['security' => $security, 'database' => ['type' => 'sqlite']]);

        return new LoginAttemptService(new DbLoginAttemptRepository($this->db, $config), $config);
    }

    private function lockoutConfig(array $overrides = []): array
    {
        return [
            'account_lockout' => array_merge([
                'enable_lockout' => true,
                'lockout_attempts' => 3,
                'lockout_duration' => 15,
                'track_ip_address' => true,
                'clear_attempts_on_success' => true,
                'whitelist_ip_addresses' => [],
                'blacklist_ip_addresses' => [],
            ], $overrides),
            'mfa' => ['max_verify_attempts' => 2, 'verify_lockout_duration' => 15],
        ];
    }

    private function attempts(): array
    {
        return $this->db->query('SELECT user_id, ip_address, successful, attempt_type FROM login_attempts ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testRecordAttemptResolvesTheUserIdFromTheUsername(): void
    {
        $this->service($this->lockoutConfig())->recordAttempt('alice', '10.0.0.1', false);

        $rows = $this->attempts();
        $this->assertCount(1, $rows);
        $this->assertSame('1', (string)$rows[0]['user_id']);
        $this->assertSame('10.0.0.1', $rows[0]['ip_address']);
        $this->assertSame('0', (string)$rows[0]['successful']);
        $this->assertSame('password', $rows[0]['attempt_type']);
    }

    public function testAccountLocksOnTheConfiguredAttemptCount(): void
    {
        $service = $this->service($this->lockoutConfig());

        $service->recordAttempt('alice', '10.0.0.1', false);
        $service->recordAttempt('alice', '10.0.0.1', false);
        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.1'));

        $service->recordAttempt('alice', '10.0.0.1', false);
        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.1'));
    }

    public function testFailuresOlderThanTheWindowDoNotCount(): void
    {
        $service = $this->service($this->lockoutConfig());
        $stale = time() - (16 * 60);
        for ($i = 0; $i < 5; $i++) {
            $this->db->exec("INSERT INTO login_attempts (user_id, ip_address, timestamp, successful, attempt_type)
                VALUES (1, '10.0.0.1', $stale, 0, 'password')");
        }

        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.1'));
    }

    public function testLockoutIsScopedToTheAddressWhenIpTrackingIsOn(): void
    {
        $service = $this->service($this->lockoutConfig());
        for ($i = 0; $i < 3; $i++) {
            $service->recordAttempt('alice', '10.0.0.1', false);
        }

        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.1'));
        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.2'));
    }

    public function testLockoutIgnoresTheAddressWhenIpTrackingIsOff(): void
    {
        $service = $this->service($this->lockoutConfig(['track_ip_address' => false]));
        for ($i = 0; $i < 3; $i++) {
            $service->recordAttempt('alice', '10.0.0.1', false);
        }

        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.2'));
    }

    public function testSuccessfulLoginClearsTheFailuresForThatAddressOnly(): void
    {
        $service = $this->service($this->lockoutConfig());
        $service->recordAttempt('alice', '10.0.0.1', false);
        $service->recordAttempt('alice', '10.0.0.2', false);

        $service->recordAttempt('alice', '10.0.0.1', true);

        $rows = $this->attempts();
        $this->assertCount(2, $rows);
        $this->assertSame('10.0.0.2', $rows[0]['ip_address']);
        $this->assertSame('10.0.0.1', $rows[1]['ip_address']);
        $this->assertSame('1', (string)$rows[1]['successful']);
    }

    public function testPasswordSuccessDoesNotClearTheMfaCounter(): void
    {
        $service = $this->service($this->lockoutConfig());
        $service->recordAttempt('alice', '10.0.0.1', false, LoginAttemptService::STAGE_MFA);
        $service->recordAttempt('alice', '10.0.0.1', false, LoginAttemptService::STAGE_MFA);

        $service->recordAttempt('alice', '10.0.0.1', true);

        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.1', LoginAttemptService::STAGE_MFA));
    }

    public function testMfaStageCountsEveryAddressAndIsThrottledWithoutLockout(): void
    {
        $service = $this->service($this->lockoutConfig(['enable_lockout' => false]));
        $service->recordAttempt('alice', '10.0.0.1', false, LoginAttemptService::STAGE_MFA);
        $service->recordAttempt('alice', '10.0.0.2', false, LoginAttemptService::STAGE_MFA);

        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.9', LoginAttemptService::STAGE_MFA));
    }

    public function testMfaSuccessClearsOnlyTheMfaFailures(): void
    {
        $service = $this->service($this->lockoutConfig());
        $service->recordAttempt('alice', '10.0.0.1', false);
        $service->recordAttempt('alice', '10.0.0.1', false, LoginAttemptService::STAGE_MFA);

        $service->recordAttempt('alice', '10.0.0.1', true, LoginAttemptService::STAGE_MFA, 1);

        $rows = $this->attempts();
        $this->assertCount(2, $rows);
        $this->assertSame('password', $rows[0]['attempt_type']);
        $this->assertSame('mfa', $rows[1]['attempt_type']);
        $this->assertSame('1', (string)$rows[1]['successful']);
    }

    public function testAttemptsAreRecordedForTheExplicitUserIdWithoutAUsername(): void
    {
        $this->service($this->lockoutConfig())->recordAttempt('', '10.0.0.1', false, LoginAttemptService::STAGE_MFA, 2);

        $rows = $this->attempts();
        $this->assertCount(1, $rows);
        $this->assertSame('2', (string)$rows[0]['user_id']);
    }

    public function testRecordingPrunesAttemptsOlderThanTheLongestWindow(): void
    {
        $service = $this->service($this->lockoutConfig());
        $stale = time() - (16 * 60);
        $this->db->exec("INSERT INTO login_attempts (user_id, ip_address, timestamp, successful, attempt_type)
            VALUES (2, '10.0.0.5', $stale, 0, 'password')");

        $service->recordAttempt('alice', '10.0.0.1', false);

        $rows = $this->attempts();
        $this->assertCount(1, $rows);
        $this->assertSame('1', (string)$rows[0]['user_id']);
    }

    public function testUnknownUsernameNeverLocksThePasswordStage(): void
    {
        $service = $this->service($this->lockoutConfig());

        $this->assertFalse($service->isAccountLocked('nosuchuser', '10.0.0.1'));
        $this->assertTrue($service->isAccountLocked('nosuchuser', '10.0.0.1', LoginAttemptService::STAGE_MFA));
    }

    public function testBlacklistedAddressLocksBeforeAnyCounting(): void
    {
        $service = $this->service($this->lockoutConfig(['blacklist_ip_addresses' => ['10.0.0.1']]));

        $this->assertTrue($service->isAccountLocked('alice', '10.0.0.1'));
    }

    public function testWhitelistedAddressIsNeverLockedOnThePasswordStage(): void
    {
        $service = $this->service($this->lockoutConfig([
            'whitelist_ip_addresses' => ['10.0.0.0/24'],
            'blacklist_ip_addresses' => ['10.0.0.1'],
        ]));
        for ($i = 0; $i < 5; $i++) {
            $service->recordAttempt('alice', '10.0.0.1', false);
        }

        $this->assertFalse($service->isAccountLocked('alice', '10.0.0.1'));
    }
}
