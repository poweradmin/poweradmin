<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for hostname validation logic
 */
class HostnameValidationTest extends BaseDnsTest
{
    public function testIsValidHostnameFqdn()
    {
        // This test now verifies that the DNS class correctly delegates to the HostnameValidator
        // Configure a mock HostnameValidator to verify delegation
        $mockHostnameValidator = $this->createMock(HostnameValidator::class);

        // Set expectations for the mock
        $mockHostnameValidator->expects($this->once())
            ->method('isValidHostnameFqdn')
            ->with('example.com', 0)
            ->willReturn(['hostname' => 'example.com']);

        // Create reflection to set the protected property
        $reflection = new \ReflectionObject($this->dnsInstance);
        $hostnameValidatorProperty = $reflection->getProperty('hostnameValidator');
        $hostnameValidatorProperty->setAccessible(true);
        $hostnameValidatorProperty->setValue($this->dnsInstance, $mockHostnameValidator);

        // Test that Dns.is_valid_hostname_fqdn delegates to HostnameValidator.isValidHostnameFqdn
        $result = $this->dnsInstance->is_valid_hostname_fqdn('example.com', 0);
        $this->assertEquals(['hostname' => 'example.com'], $result);
    }

    /**
     * Test that normalize_record_name delegates to HostnameValidator.normalizeRecordName
     */
    public function testNormalizeRecordName()
    {
        // Configure a mock HostnameValidator to verify delegation
        $mockHostnameValidator = $this->createMock(HostnameValidator::class);

        // Set expectations for the mock
        $mockHostnameValidator->expects($this->once())
            ->method('normalizeRecordName')
            ->with('www', 'example.com')
            ->willReturn('www.example.com');

        // Create reflection to set the protected property
        $reflection = new \ReflectionObject($this->dnsInstance);
        $hostnameValidatorProperty = $reflection->getProperty('hostnameValidator');
        $hostnameValidatorProperty->setAccessible(true);
        $hostnameValidatorProperty->setValue($this->dnsInstance, $mockHostnameValidator);

        // Test that Dns.normalize_record_name delegates to HostnameValidator.normalizeRecordName
        $result = $this->dnsInstance->normalize_record_name('www', 'example.com');
        $this->assertEquals('www.example.com', $result);
    }

    /**
     * Test that endsWith properly delegates to the HostnameValidator
     */
    public function testEndsWith()
    {
        // Test that Dns.endsWith delegates to HostnameValidator.endsWith
        // by using actual implementation and comparing results

        $cases = [
            ['needle' => 'com', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => 'example.com', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => '', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => 'test', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'com.example', 'haystack' => 'example.com', 'expected' => false],
        ];

        foreach ($cases as $case) {
            $this->assertEquals(
                $case['expected'],
                Dns::endsWith($case['needle'], $case['haystack']),
                "Failed assertion for needle: {$case['needle']}, haystack: {$case['haystack']}"
            );
        }
    }
}
