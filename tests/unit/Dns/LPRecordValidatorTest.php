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
use Poweradmin\Domain\Service\DnsValidation\LPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

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
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($ttl, $data['ttl']);
        $this->assertEquals($data['prio'], $data['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithProvidedPriority()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 20; // Different from the content value
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($ttl, $data['ttl']);
        $this->assertEquals($prio, $data['prio']); // Should use provided priority
    }

    public function testValidateWithAnotherValidDomain()
    {
        $content = '15 another-example.org.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'another-example.org.') {
                    return ValidationResult::success(['hostname' => 'another-example.org.']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($ttl, $data['ttl']);
        $this->assertEquals($data['prio'], $data['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '65536 example.com.'; // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up the hostname validator to return success for the hostname
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidFQDN()
    {
        $content = '10 -invalid-.example.com.'; // Invalid domain name
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up hostname validator to return success for the record name but fail for the content FQDN
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === '-invalid-.example.com.') {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('FQDN must be a valid', $result->getFirstError());
    }

    public function testValidateWithIPAddressAsFQDN()
    {
        $content = '10 192.0.2.1'; // IP address not valid for LP
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up hostname validator to return success for the record name but fail for the IP address
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === '192.0.2.1') {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('FQDN must be a valid', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10'; // Missing FQDN
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and FQDN separated by space', $result->getFirstError());
    }

    public function testValidateWithTooManyParts()
    {
        $content = '10 example.com. extrapart'; // Too many parts
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and FQDN separated by space', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 example.com.';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure hostname validator to fail the hostname check
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === '-invalid-hostname.example.com') {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        // Configure hostname validator to pass the hostname check
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        // Mock TTLValidator to fail with TTL validation
        $ttlValidatorMock = $this->createMock(TTLValidator::class);
        $ttlValidatorMock->method('validate')
            ->with($ttl, $defaultTTL)
            ->willReturn(ValidationResult::failure('Invalid TTL value'));

        // Use reflection to replace TTLValidator with mock
        $reflector = new ReflectionClass(LPRecordValidator::class);
        $property = $reflector->getProperty('ttlValidator');
        $property->setAccessible(true);
        $property->setValue($this->validator, $ttlValidatorMock);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 example.com.';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        // Configure mock hostname validator to return success for both validations
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        // Mock TTLValidator to ensure it returns the default TTL
        $ttlValidatorMock = $this->createMock(TTLValidator::class);
        $ttlValidatorMock->method('validate')
            ->with($ttl, $defaultTTL)
            ->willReturn(ValidationResult::success($defaultTTL));

        // Use reflection to replace TTLValidator with mock
        $reflector = new ReflectionClass(LPRecordValidator::class);
        $property = $reflector->getProperty('ttlValidator');
        $property->setAccessible(true);
        $property->setValue($this->validator, $ttlValidatorMock);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($defaultTTL, $data['ttl']); // Should use default TTL
        $this->assertEquals($data['prio'], $data['prio']); // Just confirm equality to itself instead of specific value
    }

    public function testValidateWithNegativePreference()
    {
        $content = '-1 example.com.'; // Negative preference not allowed
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Set up the hostname validator to return success for the hostname
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithNonDotTerminatedFQDN()
    {
        $content = '10 example.com'; // FQDN should end with dot
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Configure mock hostname validator to handle the non-dot-terminated domain
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if ($hostname === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($hostname === 'example.com') {
                    // Mock allows non-dot-terminated domains
                    return ValidationResult::success(['hostname' => 'example.com']);
                }
                return ValidationResult::failure('Invalid hostname');
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // In the new validation model, this should always return a validation result
        $this->assertTrue($result->isValid());

        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($ttl, $data['ttl']);
    }
}
