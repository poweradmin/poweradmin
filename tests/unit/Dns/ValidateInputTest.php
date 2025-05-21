<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Tests for the validate_input method
 */
class ValidateInputTest extends BaseDnsTest
{
    /**
     * Test ARecordValidator class with ValidationResult pattern
     */
    public function testARecordValidatorWithValidationResult()
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

        // Check valid result
        $this->assertTrue($result->isValid(), "A record validation should succeed for valid data");

        // Get data from ValidationResult
        $data = $result->getData();

        // Check the correct structure
        $this->assertIsArray($data, "A record validation result data should be an array");
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('prio', $data);
        $this->assertArrayHasKey('ttl', $data);

        // Check the values
        $this->assertEquals('192.168.1.1', $data['content']);
        $this->assertIsString($data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Test with invalid data - wrong IP format
        $invalidResult = $validator->validate(
            'invalid-ip',       // content
            'test.example.com', // name
            0,                  // prio
            3600,               // ttl
            86400               // default TTL
        );

        $this->assertFalse($invalidResult->isValid(), "A record validation should fail for invalid IP address");
        $this->assertNotEmpty($invalidResult->getErrors(), "Should have error messages for invalid IP");

        // Test with invalid priority
        $invalidPrioResult = $validator->validate(
            '192.168.1.1',      // content
            'test.example.com', // name
            10,                 // prio - should be 0 for A records
            3600,               // ttl
            86400               // default TTL
        );

        $this->assertFalse($invalidPrioResult->isValid(), "A record validation should fail for non-zero priority");
        $this->assertNotEmpty($invalidPrioResult->getErrors(), "Should have error messages for invalid priority");
    }

    /**
     * Test TTL validation handling with ValidationResult pattern
     */
    public function testValidateTTLHandlingWithValidationResult()
    {
        $validator = new TTLValidator();

        // Test with an empty TTL
        $ttl = "";
        $defaultTtl = 3600;

        $result = $validator->validate($ttl, $defaultTtl);

        // Check that the method returns ValidationResult with default TTL value
        $this->assertTrue($result->isValid(), "TTL validation should succeed for empty value");
        $this->assertEquals(['ttl' => 3600], $result->getData(), "TTL validator should return default TTL for empty value");

        // Test with invalid TTL
        $ttl = -1;
        $result = $validator->validate($ttl, $defaultTtl);
        $this->assertFalse($result->isValid(), "TTL validation should fail for negative TTL");
        $this->assertNotEmpty($result->getErrors(), "TTL validation should have error messages for negative TTL");

        // Test with valid TTL
        $ttl = 86400;
        $result = $validator->validate($ttl, $defaultTtl);
        $this->assertTrue($result->isValid(), "TTL validation should succeed for valid TTL");
        $this->assertEquals(['ttl' => 86400], $result->getData(), "TTL validator should return the input TTL value when valid");
    }

    /**
     * Test TTLValidator thoroughly with ValidationResult pattern
     */
    public function testTTLValidatorThoroughWithValidationResult()
    {
        $ttlValidator = new TTLValidator();

        // Valid TTL values
        $ttl = 3600;
        $result = $ttlValidator->validate($ttl, 86400);
        $this->assertTrue($result->isValid(), "Should validate the TTL value");
        $this->assertEquals(['ttl' => 3600], $result->getData(), "Should return the valid TTL value");

        $ttl = 86400;
        $result = $ttlValidator->validate($ttl, 3600);
        $this->assertTrue($result->isValid());
        $this->assertEquals(['ttl' => 86400], $result->getData(), "Should return the valid TTL value");

        $ttl = 0;
        $result = $ttlValidator->validate($ttl, 3600);
        $this->assertTrue($result->isValid());
        $this->assertEquals(['ttl' => 0], $result->getData(), "Should return 0 for a zero TTL");

        $ttl = 2147483647; // Max 32-bit signed integer
        $result = $ttlValidator->validate($ttl, 3600);
        $this->assertTrue($result->isValid());
        $this->assertEquals(['ttl' => 2147483647], $result->getData(), "Should return the max TTL value");

        // Empty TTL test - should return the default value
        $ttl = "";
        $result = $ttlValidator->validate($ttl, 3600);
        $this->assertTrue($result->isValid());
        $this->assertEquals(['ttl' => 3600], $result->getData(), "Should return the default TTL for empty value");

        // Invalid TTL values
        $ttl = -1;
        $result = $ttlValidator->validate($ttl, 3600);
        $this->assertFalse($result->isValid(), "Should fail for negative TTL");
        $this->assertNotEmpty($result->getErrors());

        $ttl = PHP_INT_MAX; // Test with maximum integer value
        if (PHP_INT_MAX > 2147483647) { // Only run this test if PHP_INT_MAX is larger than max 32-bit int
            $result = $ttlValidator->validate($ttl, 3600);
            $this->assertFalse($result->isValid(), "Should fail for TTL too large");
            $this->assertNotEmpty($result->getErrors());
        }
    }

    /**
     * Test priority validation with ValidationResult pattern
     */
    public function testValidatePriorityWithValidationResult()
    {
        // Create validator with mocks
        $dbMock = $this->createMock(PDOCommon::class);
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new DnsCommonValidator($dbMock, $configMock);

        // Test with valid values
        $result = $validator->validatePriority(10, "MX");
        $this->assertTrue($result->isValid(), "MX priority validation should succeed for valid value");
        $this->assertEquals(10, $result->getData(), "Should return the input value for valid MX priority");

        $result = $validator->validatePriority(65535, "SRV");
        $this->assertTrue($result->isValid(), "SRV priority validation should succeed for max value");
        $this->assertEquals(65535, $result->getData(), "Should return the input value for valid SRV priority");

        // Test with empty values (default values)
        $result = $validator->validatePriority("", "MX");
        $this->assertTrue($result->isValid(), "MX priority validation should succeed for empty value");
        $this->assertEquals(10, $result->getData(), "Should return default value 10 for empty MX priority");

        $result = $validator->validatePriority("", "SRV");
        $this->assertTrue($result->isValid(), "SRV priority validation should succeed for empty value");
        $this->assertEquals(10, $result->getData(), "Should return default value 10 for empty SRV priority");

        $result = $validator->validatePriority("", "A");
        $this->assertTrue($result->isValid(), "A priority validation should succeed for empty value");
        $this->assertEquals(0, $result->getData(), "Should return default value 0 for empty priority on non-MX/SRV records");

        // Test with invalid values
        $result = $validator->validatePriority(-1, "MX");
        $this->assertFalse($result->isValid(), "MX priority validation should fail for negative value");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for negative priority");

        $result = $validator->validatePriority("foo", "SRV");
        $this->assertFalse($result->isValid(), "SRV priority validation should fail for non-numeric value");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for non-numeric priority");

        // For non-MX/SRV records, any priority value should be converted to 0
        $result = $validator->validatePriority(10, "A");
        $this->assertTrue($result->isValid(), "A priority validation should succeed for any value");
        $this->assertEquals(0, $result->getData(), "Should return 0 for A record regardless of priority value");

        $result = $validator->validatePriority("invalid", "TXT");
        $this->assertTrue($result->isValid(), "TXT priority validation should succeed for any value");
        $this->assertEquals(0, $result->getData(), "Should return 0 for TXT record regardless of priority value");

        $result = $validator->validatePriority("0", "A");
        $this->assertTrue($result->isValid(), "A priority validation should succeed for zero value");
        $this->assertEquals(0, $result->getData(), "Should return 0 for A record with zero priority");
    }

    // Old TTLValidator test removed - replaced by testTTLValidatorThoroughWithValidationResult
}
