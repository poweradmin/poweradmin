<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPSECKEYRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the IPSECKEYRecordValidator using ValidationResult
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

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check for security warnings per RFC 4025
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for the DNSSEC security warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('DNSSEC', $warningText);
        $this->assertStringContainsString('RFC 4025', $warningText);
    }

    public function testValidateWithValidDataIPv4Gateway()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithValidDataIPv6Gateway()
    {
        $content = '10 2 2 2001:db8::1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithValidDataDomainNameGateway()
    {
        // Create a mock HostnameValidator that returns success for the domain gateway
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                return ValidationResult::success(['hostname' => $hostname]);
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

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check for domain name gateway specific warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('domain name gateways', $warningText);
        $this->assertStringContainsString('DNSSEC', $warningText);
    }

    public function testValidateWithInvalidDomainNameGateway()
    {
        // Create a mock HostnameValidator that fails for the domain gateway
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                // Fail for the gateway domain name validation but pass for the record name
                if ($hostname === 'invalid-gateway!.com') {
                    return ValidationResult::failure('Invalid domain name');
                }
                return ValidationResult::success(['hostname' => $hostname]);
            });

        // Inject the mock into the validator instance
        $reflectionProperty = new \ReflectionProperty(IPSECKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $hostnameValidatorMock);

        $content = '10 3 2 invalid-gateway!.com AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('gateway must be a valid domain name', $result->getFirstError());
    }

    public function testValidateWithInvalidPrecedence()
    {
        $content = '256 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Precedence > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('precedence must be', $result->getFirstError());
    }

    public function testValidateWithInvalidGatewayType()
    {
        $content = '10 4 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Gateway type 4 is invalid
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('gateway type must be', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '10 1 5 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Algorithm 5 is invalid
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('algorithm must be', $result->getFirstError());
    }

    public function testValidateWithMismatchedGatewayForType()
    {
        $content = '10 1 2 2001:db8::1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // IPv6 address for type 1 (IPv4)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('gateway must be a valid IPv4', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10 1 2'; // Missing gateway and public key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        // Mock the hostname validator to fail validation
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::failure('Invalid hostname'));

        // Inject the mock into the validator instance
        $reflectionProperty = new \ReflectionProperty(IPSECKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $hostnameValidatorMock);

        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // We don't check the exact error message as it comes from the TTL validator
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNoKeyForAlgorithm0()
    {
        // Mock the hostname validator for basic validation
        $hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'host.example.com']));

        // Inject the mock into the validator instance
        $reflectionProperty = new \ReflectionProperty(IPSECKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $hostnameValidatorMock);

        // In IPSECKEY records, the validator requires 5 fields minimum according to validateIPSECKEYContent
        // So we must include a key field even with algorithm 0
        $content = '10 1 0 192.0.2.1 EMPTY_KEY';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check for algorithm-specific warning about no key
        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('algorithm is 0', $warningText);
    }

    public function testValidateWithRSAAlgorithmSpecificWarning()
    {
        // Test with RSA key (algorithm 1)
        $content = '10 1 1 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // Algorithm 1 = RSA
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for RSA-specific warning per RFC 3110
        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('RSA keys', $warningText);
        $this->assertStringContainsString('RFC 3110', $warningText);
    }

    public function testValidateWithInvalidBase64PublicKey()
    {
        // Test with invalid Base64 encoding in public key
        $content = '10 1 1 192.0.2.1 INVALID_BASE64!@#'; // Invalid Base64 encoding
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Base64', $result->getFirstError());
    }

    public function testValidateWithInvalidNoGatewayValue()
    {
        $content = '10 0 2 invalid AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ=='; // For type 0, gateway must be "."
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('gateway must be "."', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
        $name = 'host.example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }
}
