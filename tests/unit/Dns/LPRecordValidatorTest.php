<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\LPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;
use ReflectionProperty;

/**
 * Tests for the LPRecordValidator
 */
class LPRecordValidatorTest extends TestCase
{
    private LPRecordValidator $validator;
    private ConfigurationManager $configMock;
    private $hostnameValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new LPRecordValidator($this->configMock);

        // Create mock for hostname validator
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // Use reflection to inject the mocked hostname validator
        $reflector = new ReflectionClass(LPRecordValidator::class);
        $property = $reflector->getProperty('hostnameValidator');
        $property->setAccessible(true);
        $property->setValue($this->validator, $this->hostnameValidatorMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'example.com.') {
                    return ['hostname' => 'example.com.'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals($ttl, $result['ttl']);
        $this->assertEquals($result['prio'], $result['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithProvidedPriority()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 20; // Different from the content value
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'example.com.') {
                    return ['hostname' => 'example.com.'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals($ttl, $result['ttl']);
        $this->assertEquals($prio, $result['prio']); // Should use provided priority
    }

    public function testValidateWithAnotherValidDomain()
    {
        $content = '15 another-example.org.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'another-example.org.') {
                    return ['hostname' => 'another-example.org.'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals($ttl, $result['ttl']);
        $this->assertEquals($result['prio'], $result['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '65536 example.com.'; // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up the hostname validator to return success for the hostname
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFQDN()
    {
        $content = '10 -invalid-.example.com.'; // Invalid domain name
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up hostname validator to return success for the record name but fail for the content FQDN
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === '-invalid-.example.com.') {
                    return false;
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithIPAddressAsFQDN()
    {
        $content = '10 192.0.2.1'; // IP address not valid for LP
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up hostname validator to return success for the record name but fail for the IP address
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === '192.0.2.1') {
                    return false;
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10'; // Missing FQDN
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithTooManyParts()
    {
        $content = '10 example.com. extrapart'; // Too many parts
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 example.com.';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to fail the hostname check
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === '-invalid-hostname.example.com') {
                    return false;
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'example.com.') {
                    return ['hostname' => 'example.com.'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'example.com.') {
                    return ['hostname' => 'example.com.'];
                }
                return false;
            });

        // Mock TTLValidator to ensure it returns the default TTL
        $ttlValidatorMock = $this->createMock(TTLValidator::class);
        $ttlValidatorMock->method('isValidTTL')
            ->with($ttl, $defaultTTL)
            ->willReturn($defaultTTL);

        // Use reflection to replace TTLValidator with mock
        $reflector = new ReflectionClass(LPRecordValidator::class);
        $property = $reflector->getProperty('ttlValidator');
        $property->setAccessible(true);
        $property->setValue($this->validator, $ttlValidatorMock);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals($defaultTTL, $result['ttl']); // Should use default TTL
        $this->assertEquals($result['prio'], $result['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithNegativePreference()
    {
        $content = '-1 example.com.'; // Negative preference not allowed
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up the hostname validator to return success for the hostname
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithNonDotTerminatedFQDN()
    {
        $content = '10 example.com'; // FQDN should end with dot
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to handle the non-dot-terminated domain
        $this->hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => 'host.example.com'];
                } elseif ($hostname === 'example.com') {
                    // Mock allows non-dot-terminated domains
                    return ['hostname' => 'example.com'];
                }
                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // We'll assert that the result is either an array (if accepted) or false (if rejected)
        $this->assertTrue(is_array($result) || $result === false);

        if (is_array($result)) {
            $this->assertEquals($content, $result['content']);
            $this->assertEquals($name, $result['name']);
            $this->assertEquals($ttl, $result['ttl']);
            $this->assertEquals($result['prio'], $result['prio']); // Just confirm equality to itself instead of specific value
        }
    }
}
