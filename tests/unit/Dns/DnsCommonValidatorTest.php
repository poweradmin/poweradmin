<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for common DNS validation functions
 */
class DnsCommonValidatorTest extends TestCase
{
    private DnsCommonValidator $validator;
    private PDOLayer $dbMock;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new DnsCommonValidator($this->dbMock, $this->configMock);
    }

    /**
     * Test priority validation
     */
    public function testValidPriority()
    {
        // Test default values
        $this->assertEquals(10, $this->validator->isValidPriority(null, "MX"));
        $this->assertEquals(10, $this->validator->isValidPriority("", "MX"));
        $this->assertEquals(0, $this->validator->isValidPriority(null, "A"));
        $this->assertEquals(0, $this->validator->isValidPriority("", "A"));

        // Test valid MX/SRV priorities
        $this->assertEquals(0, $this->validator->isValidPriority(0, "MX"));
        $this->assertEquals(10, $this->validator->isValidPriority(10, "MX"));
        $this->assertEquals(65535, $this->validator->isValidPriority(65535, "MX"));
        $this->assertEquals(0, $this->validator->isValidPriority(0, "SRV"));
        $this->assertEquals(10, $this->validator->isValidPriority(10, "SRV"));
        $this->assertEquals(65535, $this->validator->isValidPriority(65535, "SRV"));

        // Test invalid priorities for MX/SRV records
        $this->assertFalse($this->validator->isValidPriority(-1, "MX"));
        $this->assertFalse($this->validator->isValidPriority(65536, "MX"));
        $this->assertFalse($this->validator->isValidPriority("invalid", "MX"));

        // Test non-MX/SRV records - should always return 0 regardless of input
        $this->assertEquals(0, $this->validator->isValidPriority(10, "A"));
        $this->assertEquals(0, $this->validator->isValidPriority(100, "AAAA"));
        $this->assertEquals(0, $this->validator->isValidPriority("invalid", "TXT"));
    }

    /**
     * Test non-alias target validation
     */
    public function testValidNonAliasTargetNoCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for query results
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(false);

        $this->assertTrue($this->validator->isValidNonAliasTarget("example.com"));
    }

    public function testValidNonAliasTargetWithCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for query results
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(['id' => 1]);

        $this->assertFalse($this->validator->isValidNonAliasTarget("has.cname.example.com"));
    }
}
