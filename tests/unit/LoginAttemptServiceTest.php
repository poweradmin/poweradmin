<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Domain\Repository\LoginAttemptRepositoryInterface;
use TestHelpers\FakeConfiguration;

class LoginAttemptServiceTest extends TestCase
{
    private $attemptsMock;

    protected function setUp(): void
    {
        $this->attemptsMock = $this->createMock(LoginAttemptRepositoryInterface::class);
        $this->attemptsMock->method('hasAttemptTypeColumn')->willReturn(true);
    }

    private function service(array $security = [], array $database = []): LoginAttemptService
    {
        $config = new FakeConfiguration(['security' => $security, 'database' => $database]);

        return new LoginAttemptService($this->attemptsMock, $config);
    }

    public function testIsAccountLockedReturnsFalseWhenAccountLockoutDisabled()
    {
        $service = $this->service(['account_lockout' => ['enable_lockout' => false]]);

        $result = $service->isAccountLocked('testuser', '192.168.1.1');
        $this->assertFalse($result);
    }

    public function testMfaStageIsThrottledWhenAccountLockoutDisabled()
    {
        $service = $this->service([
            'account_lockout' => ['enable_lockout' => false, 'blacklist_ip_addresses' => []],
            'mfa' => ['max_verify_attempts' => 5, 'verify_lockout_duration' => 15],
        ], ['type' => 'mysql']);

        $this->attemptsMock->method('findUserIdByUsername')->willReturn(1);
        $this->attemptsMock->method('countFailedAttempts')->willReturn(5);

        $result = $service->isAccountLocked(
            'testuser',
            '192.168.1.1',
            LoginAttemptService::STAGE_MFA
        );

        $this->assertTrue($result, 'The second factor must throttle without opting into account lockout');
    }

    public function testMfaStageLocksWhenUsernameDoesNotResolve()
    {
        $service = $this->service([
            'account_lockout' => ['enable_lockout' => false, 'blacklist_ip_addresses' => []],
            'mfa' => ['max_verify_attempts' => 5, 'verify_lockout_duration' => 15],
        ], ['type' => 'mysql']);

        // The username resolves to no account, so the attempt cannot be counted.
        $this->attemptsMock->method('findUserIdByUsername')->willReturn(null);

        $this->assertTrue(
            $service->isAccountLocked('nosuchuser', '192.168.1.1', LoginAttemptService::STAGE_MFA),
            'An uncountable second-factor attempt must fail closed, not drop the limit'
        );

        $this->assertFalse(
            $service->isAccountLocked('nosuchuser', '192.168.1.1'),
            'The password stage must still let unknown usernames reach the authenticator'
        );
    }

    public function testMfaStageIgnoresWhitelistedIp()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => false,
                'whitelist_ip_addresses' => ['192.168.1.1'],
                'blacklist_ip_addresses' => [],
            ],
            'mfa' => ['max_verify_attempts' => 5, 'verify_lockout_duration' => 15],
        ], ['type' => 'mysql']);

        $this->attemptsMock->method('findUserIdByUsername')->willReturn(1);
        $this->attemptsMock->method('countFailedAttempts')->willReturn(5);

        $result = $service->isAccountLocked(
            'testuser',
            '192.168.1.1',
            LoginAttemptService::STAGE_MFA
        );

        $this->assertTrue($result, 'A whitelisted address must not exempt the second factor from throttling');
    }

    public function testWhitelistedIpIsNeverLocked()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => ['192.168.1.1', '10.0.0.0/24'],
                'blacklist_ip_addresses' => ['192.168.1.1'], // Even if IP is also blacklisted
            ],
        ]);

        // Ensure the method returns false (not locked) for a whitelisted IP
        $result = $service->isAccountLocked('testuser', '192.168.1.1');
        $this->assertFalse($result);
    }

    public function testBlacklistedIpIsAlwaysLocked()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => [],
                'blacklist_ip_addresses' => ['192.168.1.2'],
            ],
        ]);

        // Test direct IP match in blacklist
        $blacklistedIps = ['192.168.1.2'];
        $result = $service->isIpInList('192.168.1.2', $blacklistedIps);
        $this->assertTrue($result, "IP should match exact entry in blacklist");

        $this->attemptsMock->method('findUserIdByUsername')->willReturn(1);

        // Ensure the method returns true (locked) for a blacklisted IP
        $result = $service->isAccountLocked('testuser', '192.168.1.2');
        $this->assertTrue($result);
    }

    public function testCidrNotationInWhitelist()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => ['10.0.0.0/24'],
                'blacklist_ip_addresses' => [],
            ],
        ]);

        // Ensure the method returns false (not locked) for an IP in the whitelisted CIDR range
        $result = $service->isAccountLocked('testuser', '10.0.0.15');
        $this->assertFalse($result);
    }

    public function testCidrNotationInBlacklist()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => [],
                'blacklist_ip_addresses' => ['172.16.0.0/16'],
            ],
        ]);

        // Test CIDR notation directly
        $blacklistedIps = ['172.16.0.0/16'];
        $result = $service->isIpInList('172.16.10.5', $blacklistedIps);
        $this->assertTrue($result, "IP should match CIDR notation in blacklist");

        $this->attemptsMock->method('findUserIdByUsername')->willReturn(1);

        // Ensure the method returns true (locked) for an IP in the blacklisted CIDR range
        $result = $service->isAccountLocked('testuser', '172.16.10.5');
        $this->assertTrue($result);
    }

    public function testWildcardNotationInWhitelist()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => ['192.168.2.*'],
                'blacklist_ip_addresses' => [],
            ],
        ]);

        // Ensure the method returns false (not locked) for an IP matching the wildcard
        $result = $service->isAccountLocked('testuser', '192.168.2.100');
        $this->assertFalse($result);
    }

    public function testWildcardNotationInBlacklist()
    {
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => [],
                'blacklist_ip_addresses' => ['192.168.3.*'],
            ],
        ]);

        // Test wildcard notation directly
        $blacklistedIps = ['192.168.3.*'];
        $result = $service->isIpInList('192.168.3.200', $blacklistedIps);
        $this->assertTrue($result, "IP should match wildcard notation in blacklist");

        $this->attemptsMock->method('findUserIdByUsername')->willReturn(1);

        // Ensure the method returns true (locked) for an IP matching the wildcard
        $result = $service->isAccountLocked('testuser', '192.168.3.200');
        $this->assertTrue($result);
    }

    public function testWhitelistTakesPriorityOverBlacklist()
    {
        // Both whitelist and blacklist contain the same IP/range
        $service = $this->service([
            'account_lockout' => [
                'enable_lockout' => true,
                'whitelist_ip_addresses' => ['192.168.5.0/24'],
                'blacklist_ip_addresses' => ['192.168.5.0/24'],
            ],
        ]);

        // Ensure the method returns false (not locked) because whitelist takes priority
        $result = $service->isAccountLocked('testuser', '192.168.5.10');
        $this->assertFalse($result);
    }
}
