<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the HostnameValidator service
 */
class HostnameValidatorTest extends TestCase
{
    private HostnameValidator $validator;

    protected function setUp(): void
    {
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

        $this->validator = new HostnameValidator($configMock);
    }

    /**
     * Test the validate method for hostname validation
     */
    public function testValidateHostname()
    {
        // Valid hostnames
        $validResult = $this->validator->validate('example.com');
        $this->assertTrue($validResult->isValid());
        $this->assertEquals(['hostname' => 'example.com'], $validResult->getData());

        $validResult2 = $this->validator->validate('www.example.com');
        $this->assertTrue($validResult2->isValid());

        // Test with trailing dot (should be normalized)
        $validResult3 = $this->validator->validate('example.com.');
        $this->assertTrue($validResult3->isValid());
        $this->assertEquals(['hostname' => 'example.com'], $validResult3->getData());

        // Invalid hostnames
        $invalidResult = $this->validator->validate('example..com');
        $this->assertFalse($invalidResult->isValid());

        $invalidResult2 = $this->validator->validate('-example.com');
        $this->assertFalse($invalidResult2->isValid());

        $invalidResult3 = $this->validator->validate('example-.com');
        $this->assertFalse($invalidResult3->isValid());

        $tooLongLabel = str_repeat('a', 64) . '.example.com';
        $invalidResult4 = $this->validator->validate($tooLongLabel);
        $this->assertFalse($invalidResult4->isValid());

        // Test wildcard (should fail without wildcard flag)
        $wildcardResult = $this->validator->validate('*.example.com', false);
        $this->assertFalse($wildcardResult->isValid());

        // Test wildcard (should succeed with wildcard flag)
        $wildcardResult2 = $this->validator->validate('*.example.com', true);
        $this->assertTrue($wildcardResult2->isValid());
    }

/**
     * Test the normalizeRecordName function
     */
    public function testNormalizeRecordName()
    {
        // Test case the: Name without zone suffix
        $name = "www";
        $zone = "example.com";
        $expected = "www.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name already has zone suffix
        $name = "mail.example.com";
        $zone = "example.com";
        $expected = "mail.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Empty name should return zone
        $name = "";
        $zone = "example.com";
        $expected = "example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Case-insensitive matching
        $name = "SUB.EXAMPLE.COM";
        $zone = "example.com";
        $expected = "SUB.EXAMPLE.COM";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name is @ sign (should be transformed)
        $name = "@";
        $zone = "example.com";
        $expected = "@.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Subdomain of zone
        $name = "test.sub";
        $zone = "example.com";
        $expected = "test.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));
    }

    /**
     * Test the endsWith static function
     */
    public function testEndsWith()
    {
        $this->assertTrue(HostnameValidator::endsWith('com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('example.com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('', 'example.com'));

        $this->assertFalse(HostnameValidator::endsWith('test', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('com.example', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('example.com.org', 'example.com'));
    }
}
