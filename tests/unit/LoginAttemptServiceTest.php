<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use PDOStatement;

class LoginAttemptServiceTest extends TestCase
{
    private $pdoLayerMock;
    private $configManagerMock;
    private $loginAttemptService;

    protected function setUp(): void
    {
        $this->pdoLayerMock = $this->createMock(PDOLayer::class);
        $this->configManagerMock = $this->createMock(ConfigurationManager::class);
        $this->loginAttemptService = new LoginAttemptService($this->pdoLayerMock, $this->configManagerMock);
    }

    public function testIsAccountLockedReturnsFalseWhenAccountLockoutDisabled()
    {
        // Configure ConfigurationManager to return false for enable_lockout
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, false]
            ]);

        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.1.1');
        $this->assertFalse($result);
    }

    public function testWhitelistedIpIsNeverLocked()
    {
        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], ['192.168.1.1', '10.0.0.0/24']],
                ['security', 'account_lockout.blacklist_ip_addresses', [], ['192.168.1.1']] // Even if IP is also blacklisted
            ]);

        // Ensure the method returns false (not locked) for a whitelisted IP
        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.1.1');
        $this->assertFalse($result);
    }

    public function testBlacklistedIpIsAlwaysLocked()
    {
        // Test direct IP match in blacklist
        $blacklistedIps = ['192.168.1.2'];
        $result = $this->loginAttemptService->isIpInList('192.168.1.2', $blacklistedIps);
        $this->assertTrue($result, "IP should match exact entry in blacklist");

        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], []],
                ['security', 'account_lockout.blacklist_ip_addresses', [], ['192.168.1.2']]
            ]);

        // Mock the getUserId method to return a valid ID
        $pdoStatementMock = $this->createMock(PDOStatement::class);
        $pdoStatementMock->method('fetch')->willReturn(['id' => 1]);
        $pdoStatementMock->method('execute')->willReturn(true);
        $this->pdoLayerMock->method('prepare')->willReturn($pdoStatementMock);

        // Ensure the method returns true (locked) for a blacklisted IP
        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.1.2');
        $this->assertTrue($result);
    }

    public function testCidrNotationInWhitelist()
    {
        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], ['10.0.0.0/24']],
                ['security', 'account_lockout.blacklist_ip_addresses', [], []]
            ]);

        // Ensure the method returns false (not locked) for an IP in the whitelisted CIDR range
        $result = $this->loginAttemptService->isAccountLocked('testuser', '10.0.0.15');
        $this->assertFalse($result);
    }

    public function testCidrNotationInBlacklist()
    {
        // Test CIDR notation directly
        $blacklistedIps = ['172.16.0.0/16'];
        $result = $this->loginAttemptService->isIpInList('172.16.10.5', $blacklistedIps);
        $this->assertTrue($result, "IP should match CIDR notation in blacklist");

        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], []],
                ['security', 'account_lockout.blacklist_ip_addresses', [], ['172.16.0.0/16']]
            ]);

        // Mock the getUserId method to return a valid ID
        $pdoStatementMock = $this->createMock(PDOStatement::class);
        $pdoStatementMock->method('fetch')->willReturn(['id' => 1]);
        $pdoStatementMock->method('execute')->willReturn(true);
        $this->pdoLayerMock->method('prepare')->willReturn($pdoStatementMock);

        // Ensure the method returns true (locked) for an IP in the blacklisted CIDR range
        $result = $this->loginAttemptService->isAccountLocked('testuser', '172.16.10.5');
        $this->assertTrue($result);
    }

    public function testWildcardNotationInWhitelist()
    {
        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], ['192.168.2.*']],
                ['security', 'account_lockout.blacklist_ip_addresses', [], []]
            ]);

        // Ensure the method returns false (not locked) for an IP matching the wildcard
        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.2.100');
        $this->assertFalse($result);
    }

    public function testWildcardNotationInBlacklist()
    {
        // Test wildcard notation directly
        $blacklistedIps = ['192.168.3.*'];
        $result = $this->loginAttemptService->isIpInList('192.168.3.200', $blacklistedIps);
        $this->assertTrue($result, "IP should match wildcard notation in blacklist");

        // Configure ConfigurationManager to return necessary values
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], []],
                ['security', 'account_lockout.blacklist_ip_addresses', [], ['192.168.3.*']]
            ]);

        // Mock the getUserId method to return a valid ID
        $pdoStatementMock = $this->createMock(PDOStatement::class);
        $pdoStatementMock->method('fetch')->willReturn(['id' => 1]);
        $pdoStatementMock->method('execute')->willReturn(true);
        $this->pdoLayerMock->method('prepare')->willReturn($pdoStatementMock);

        // Ensure the method returns true (locked) for an IP matching the wildcard
        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.3.200');
        $this->assertTrue($result);
    }

    public function testWhitelistTakesPriorityOverBlacklist()
    {
        // Configure ConfigurationManager to return necessary values
        // Both whitelist and blacklist contain the same IP/range
        $this->configManagerMock->method('get')
            ->willReturnMap([
                ['security', 'account_lockout.enable_lockout', false, true],
                ['security', 'account_lockout.whitelist_ip_addresses', [], ['192.168.5.0/24']],
                ['security', 'account_lockout.blacklist_ip_addresses', [], ['192.168.5.0/24']]
            ]);

        // Ensure the method returns false (not locked) because whitelist takes priority
        $result = $this->loginAttemptService->isAccountLocked('testuser', '192.168.5.10');
        $this->assertFalse($result);
    }
}
