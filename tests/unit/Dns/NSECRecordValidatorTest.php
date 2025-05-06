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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\NSECRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

class NSECRecordValidatorTest extends TestCase
{
    private NSECRecordValidator $validator;
    private ConfigurationManager $configMock;
    private TTLValidator $ttlValidatorMock;
    private HostnameValidator $hostnameValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Create mocks for TTLValidator and HostnameValidator
        $this->ttlValidatorMock = $this->getMockBuilder(TTLValidator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->hostnameValidatorMock = $this->getMockBuilder(HostnameValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Set up default behavior for hostname validator
        $this->hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'host.example.com']));

        // Set up default behavior for TTL validator
        $this->ttlValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(3600));

        // Create validator with mocked dependencies
        $this->validator = new NSECRecordValidator($this->configMock);

        // Use reflection to inject mock dependencies
        $reflection = new ReflectionClass($this->validator);

        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);
    }

    /**
     * Test validation with valid NSEC content
     */
    public function testValidateWithValidNSECContent(): void
    {
        // Reset and set up mocks with specific expectations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // First call for the record hostname
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($name === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with(3600, 86400)
            ->willReturn(ValidationResult::success(3600));

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com. A NS SOA MX TXT AAAA',  // content
            'host.example.com',                   // name
            '',                                   // prio (empty as not used for NSEC)
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals('example.com. A NS SOA MX TXT AAAA', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
        $this->assertEquals('host.example.com', $data['name']);
    }

    /**
     * Test validation with only next domain name (no type map)
     */
    public function testValidateWithOnlyNextDomainName(): void
    {
        // Reset and set up mocks with specific expectations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // Set up hostname validator to handle both hostnames
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($name === 'example.com.') {
                    return ValidationResult::success(['hostname' => 'example.com.']);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com.',                       // content (just the next domain)
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('example.com.', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        // Set up hostname validator to succeed for the record name
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->once())
            ->method('validate')
            ->with('host.example.com', true)
            ->willReturn(ValidationResult::success(['hostname' => 'host.example.com']));

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            '',                                   // empty content
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid next domain name
     */
    public function testValidateWithInvalidNextDomainName(): void
    {
        // Reset and set up mocks with specific expectations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // Set up hostname validator to handle specific hostnames
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com') {
                    return ValidationResult::success(['hostname' => 'host.example.com']);
                } elseif ($name === 'invalid..domain.') {
                    return ValidationResult::failure('Invalid hostname format');
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'invalid..domain. A NS SOA',          // invalid content (bad domain)
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('domain name', $result->getFirstError());
    }

    /**
     * Test validation with invalid record type in the type map
     */
    public function testValidateWithInvalidRecordType(): void
    {
        // Set up hostname validator for both name validations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com' || $name === 'example.com.') {
                    return ValidationResult::success(['hostname' => $name]);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com. A NS INVALID-TYPE SOA',  // content with invalid type
            'host.example.com',                    // name
            '',                                    // prio
            3600,                                  // ttl
            86400                                  // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('invalid record type', $result->getFirstError());
        $this->assertStringContainsString('INVALID-TYPE', $result->getFirstError());
    }

    /**
     * Test validation with numeric type codes (should pass)
     */
    public function testValidateWithNumericTypeCodes(): void
    {
        // Set up hostname validator for both name validations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com' || $name === 'example.com.') {
                    return ValidationResult::success(['hostname' => $name]);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com. 1 2 6 15 16 28',        // content with numeric type codes
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals('example.com. 1 2 6 15 16 28', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with type parameters in parentheses
     */
    public function testValidateWithTypeParameters(): void
    {
        // Set up hostname validator for both name validations
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com' || $name === 'example.com.') {
                    return ValidationResult::success(['hostname' => $name]);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com. A(1) NS(2) SOA(6)',     // content with type parameters
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('example.com. A(1) NS(2) SOA(6)', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        // Set up hostname validator to succeed for both record and content
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com' || $name === 'example.com.') {
                    return ValidationResult::success(['hostname' => $name]);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Set up TTL validator to fail
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with(-1, 86400)
            ->willReturn(ValidationResult::failure('TTL must be a positive number'));

        // Set validators in the validator instance
        $reflection = new ReflectionClass($this->validator);

        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            'example.com. A NS SOA',              // content
            'host.example.com',                   // name
            '',                                   // prio
            -1,                                   // invalid ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        // Set up hostname validator to succeed for both record and content
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->exactly(2))
            ->method('validate')
            ->willReturnCallback(function ($name, $allowUnderscores) {
                if ($name === 'host.example.com' || $name === 'example.com.') {
                    return ValidationResult::success(['hostname' => $name]);
                }
                return ValidationResult::failure('Unexpected hostname: ' . $name);
            });

        // Mock TTL validator to return default TTL
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with('', 86400)
            ->willReturn(ValidationResult::success(86400));

        // Set validators in the validator instance
        $reflection = new ReflectionClass($this->validator);

        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            'example.com. A NS SOA',              // content
            'host.example.com',                   // name
            '',                                   // prio
            '',                                   // empty ttl, should use default
            86400                                 // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals('example.com. A NS SOA', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with invalid hostname
     */
    public function testValidateWithInvalidHostname(): void
    {
        // Mock hostname validator to fail for the record name
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->once())
            ->method('validate')
            ->with('invalid..hostname', true)
            ->willReturn(ValidationResult::failure('Invalid hostname format'));

        // Set hostname validator in the validator instance
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            'example.com. A NS SOA',              // content
            'invalid..hostname',                  // name (invalid)
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }
}
