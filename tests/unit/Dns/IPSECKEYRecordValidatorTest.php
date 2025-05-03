<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPSECKEYRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the IPSECKEYRecordValidator
 */
class IPSECKEYRecordValidatorTest extends TestCase
{
    private IPSECKEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new IPSECKEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidDataNoGateway()
    {
        $content = '10 0 2 . AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithValidDataIPv4Gateway()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithValidDataIPv6Gateway()
    {
        $content = '10 2 2 2001:db8::1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithValidDataDomainNameGateway()
    {
        // Create a mock HostnameValidator that returns success for the domain gateway
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com' || $hostname === 'gateway.example.com') {
                    return ['hostname' => $hostname];
                }
                return false;
            });

        // Inject the mock into the validator instance
        $reflectionProperty = new \ReflectionProperty(IPSECKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $hostnameValidatorMock);

        $content = '10 3 2 gateway.example.com AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidPrecedence()
    {
        $content = '256 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Precedence > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidGatewayType()
    {
        $content = '10 4 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Gateway type 4 is invalid
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '10 1 5 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Algorithm 5 is invalid
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithMismatchedGatewayForType()
    {
        $content = '10 1 2 2001:db8::1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // IPv6 address for type 1 (IPv4)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10 1 2'; // Missing gateway and public key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithNoKeyForAlgorithm0()
    {
        // Mock the hostname validator for basic validation
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('isValidHostnameFqdn')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ['hostname' => $hostname];
                }
                return false;
            });

        // Inject the mock into the validator instance
        $reflectionProperty = new \ReflectionProperty(IPSECKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $hostnameValidatorMock);

        // In IPSECKEY records, the validator requires 5 fields minimum according to isValidIPSECKEYContent
        // So we must include a key field even with algorithm 0
        $content = '10 1 0 192.0.2.1 EMPTY_KEY';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidNoGatewayValue()
    {
        $content = '10 0 2 invalid AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // For type 0, gateway must be "."
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }
}
