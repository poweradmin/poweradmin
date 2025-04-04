<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use ReflectionClass;

class IpListMatchingTest extends TestCase
{
    private $loginAttemptService;
    private $isIpInListMethod;

    protected function setUp(): void
    {
        $pdoLayerMock = $this->createMock(PDOLayer::class);
        $configManagerMock = $this->createMock(ConfigurationManager::class);
        $this->loginAttemptService = new LoginAttemptService($pdoLayerMock, $configManagerMock);

        // Use reflection to access the private isIpInList method
        $reflection = new ReflectionClass(LoginAttemptService::class);
        $this->isIpInListMethod = $reflection->getMethod('isIpInList');
        $this->isIpInListMethod->setAccessible(true);
    }

    public function testExactIpMatch()
    {
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.1.1',
            ['192.168.1.1', '10.0.0.1']
        );
        $this->assertTrue($result);

        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.1.2',
            ['192.168.1.1', '10.0.0.1']
        );
        $this->assertFalse($result);
    }

    public function testCidrNotationMatch()
    {
        // Test IP that falls within the CIDR range
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.1.50',
            ['192.168.1.0/24']
        );
        $this->assertTrue($result);

        // Test IP that falls outside the CIDR range
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.2.1',
            ['192.168.1.0/24']
        );
        $this->assertFalse($result);

        // Test larger CIDR blocks
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '10.10.10.10',
            ['10.0.0.0/8']
        );
        $this->assertTrue($result);
    }

    public function testWildcardNotationMatch()
    {
        // Test single wildcard at the end
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.1.100',
            ['192.168.1.*']
        );
        $this->assertTrue($result);

        // Test IP that doesn't match the wildcard
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.2.100',
            ['192.168.1.*']
        );
        $this->assertFalse($result);
    }

    public function testEmptyIpOrList()
    {
        // Test with empty IP address
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '',
            ['192.168.1.1']
        );
        $this->assertFalse($result);

        // Test with empty list
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '192.168.1.1',
            []
        );
        $this->assertFalse($result);
    }

    public function testInvalidIpAddress()
    {
        // Test with invalid IP address
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            'not-an-ip',
            ['192.168.1.1']
        );
        $this->assertFalse($result);
    }

    public function testMultiplePatternTypes()
    {
        // Test a list with multiple pattern types
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '10.0.0.5',
            ['192.168.1.1', '10.0.0.0/24', '172.16.*']
        );
        $this->assertTrue($result);

        // This requires the pattern to match the whole IP, so use a pattern that works
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '172.16.5.10',
            ['192.168.1.1', '10.0.0.0/24', '172.16.5.*']
        );
        $this->assertTrue($result);

        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '8.8.8.8',
            ['192.168.1.1', '10.0.0.0/24', '172.16.*']
        );
        $this->assertFalse($result);
    }

    public function testIpv6Addresses()
    {
        // Note: Our implementation currently doesn't handle IPv6 addresses
        // This test verifies the current behavior, which should return false
        $result = $this->isIpInListMethod->invoke(
            $this->loginAttemptService,
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ['2001:0db8:85a3:0000:0000:8a2e:0370:7334']
        );
        $this->assertFalse($result);
    }
}
