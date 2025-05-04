<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for the validate_input method
 */
class ValidateInputTest extends BaseDnsTest
{
    /**
     * Test ARecordValidator class
     */
    public function testARecordValidator()
    {
        // Create mock for config
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return null;
            });

        $validator = new ARecordValidator($configMock);

        // Test with valid data
        $result = $validator->validate(
            '192.168.1.1',      // content
            'test.example.com', // name
            0,                  // prio
            3600,               // ttl
            86400               // default TTL
        );

        // Check the correct structure
        $this->assertIsArray($result, "A record validation result should be an array");
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('prio', $result);
        $this->assertArrayHasKey('ttl', $result);

        // Check the values
        $this->assertEquals('192.168.1.1', $result['content']);
        $this->assertIsString($result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    /**
     * Test TTL validation handling
     */
    public function testValidateTTLHandling()
    {
        $validator = new TTLValidator();

        // Test with an empty TTL
        $ttl = "";
        $defaultTtl = 3600;

        $result = $validator->isValidTTL($ttl, $defaultTtl);

        // Check that the method returns the default TTL value
        $this->assertSame(3600, $result, "TTL validator should return default TTL for empty value");

        // Test with invalid TTL
        $ttl = -1;
        $result = $validator->isValidTTL($ttl, $defaultTtl);
        $this->assertFalse($result, "TTL validator should return false for negative TTL");

        // Test with valid TTL
        $ttl = 86400;
        $result = $validator->isValidTTL($ttl, $defaultTtl);
        $this->assertSame(86400, $result, "TTL validator should return the input TTL value when valid");
    }

    /**
     * Test TTLValidator's isValidTTL method
     */
    public function testTTLValidatorIsValidTTL()
    {
        $ttlValidator = new TTLValidator();

        // Test with an empty TTL
        $ttl = "";
        $defaultTtl = 3600;
        $result = $ttlValidator->isValidTTL($ttl, $defaultTtl);
        $this->assertSame(3600, $result, "TTLValidator::isValidTTL should return default TTL for empty value");

        // Test with invalid TTL
        $ttl = -1;
        $result = $ttlValidator->isValidTTL($ttl, $defaultTtl);
        $this->assertFalse($result, "TTLValidator::isValidTTL should return false for negative TTL");

        // Test with valid TTL
        $ttl = 86400;
        $result = $ttlValidator->isValidTTL($ttl, $defaultTtl);
        $this->assertSame(86400, $result, "TTLValidator::isValidTTL should return the input TTL value when valid");
    }

    public function testIsValidRRPrio()
    {
        // Create validator with mocks
        $dbMock = $this->createMock(PDOLayer::class);
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new DnsCommonValidator($dbMock, $configMock);

        // Test with valid values
        $result = $validator->isValidPriority(10, "MX");
        $this->assertSame(10, $result, "Should return the input value for valid MX priority");

        $result = $validator->isValidPriority(65535, "SRV");
        $this->assertSame(65535, $result, "Should return the input value for valid SRV priority");

        // Test with empty values (default values)
        $result = $validator->isValidPriority("", "MX");
        $this->assertSame(10, $result, "Should return default value 10 for empty MX priority");

        $result = $validator->isValidPriority("", "SRV");
        $this->assertSame(10, $result, "Should return default value 10 for empty SRV priority");

        $result = $validator->isValidPriority("", "A");
        $this->assertSame(0, $result, "Should return default value 0 for empty priority on non-MX/SRV records");

        // Test with invalid values
        $result = $validator->isValidPriority(-1, "MX");
        $this->assertFalse($result, "Should return false for negative priority");

        $result = $validator->isValidPriority("foo", "SRV");
        $this->assertFalse($result, "Should return false for non-numeric priority");

        // For non-MX/SRV records, any priority value should be converted to 0
        $result = $validator->isValidPriority(10, "A");
        $this->assertSame(0, $result, "Should return 0 for A record regardless of priority value");

        $result = $validator->isValidPriority("invalid", "TXT");
        $this->assertSame(0, $result, "Should return 0 for TXT record regardless of priority value");

        $result = $validator->isValidPriority("0", "A");
        $this->assertSame(0, $result, "Should return 0 for A record with zero priority");
    }

    /**
     * Test TTLValidator thoroughly
     */
    public function testTTLValidatorThorough()
    {
        $ttlValidator = new TTLValidator();

        // Valid TTL values
        $ttl = 3600;
        $result = $ttlValidator->isValidTTL($ttl, 86400);
        $this->assertSame(3600, $result, "Should return the valid TTL value");

        $ttl = 86400;
        $result = $ttlValidator->isValidTTL($ttl, 3600);
        $this->assertSame(86400, $result, "Should return the valid TTL value");

        $ttl = 0;
        $result = $ttlValidator->isValidTTL($ttl, 3600);
        $this->assertSame(0, $result, "Should return 0 for a zero TTL");

        $ttl = 2147483647; // Max 32-bit signed integer
        $result = $ttlValidator->isValidTTL($ttl, 3600);
        $this->assertSame(2147483647, $result, "Should return the max TTL value");

        // Empty TTL test - should return the default value
        $ttl = "";
        $result = $ttlValidator->isValidTTL($ttl, 3600);
        $this->assertSame(3600, $result, "Should return the default TTL for empty value");

        // Invalid TTL values
        $ttl = -1;
        $result = $ttlValidator->isValidTTL($ttl, 3600);
        $this->assertFalse($result, "Should return false for negative TTL");

        $ttl = PHP_INT_MAX; // Test with maximum integer value
        if (PHP_INT_MAX > 2147483647) { // Only run this test if PHP_INT_MAX is larger than max 32-bit int
            $result = $ttlValidator->isValidTTL($ttl, 3600);
            $this->assertFalse($result, "Should return false for TTL too large");
        }
    }
}
