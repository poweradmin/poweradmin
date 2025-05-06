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
use Poweradmin\Domain\Service\DnsValidation\NSEC3RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

class NSEC3RecordValidatorTest extends TestCase
{
    private NSEC3RecordValidator $validator;
    private ConfigurationManager $configMock;
    private TTLValidator $ttlValidatorMock;
    private HostnameValidator $hostnameValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Create mocks for TTLValidator and HostnameValidator
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // Set up default successful validation for hostname and TTL
        $this->hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'hash.example.com']));

        $this->ttlValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(3600));

        // Create validator with config
        $this->validator = new NSEC3RecordValidator($this->configMock);

        // Inject mock dependencies using reflection
        $reflection = new ReflectionClass($this->validator);

        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);
    }

    /**
     * Test validation with valid NSEC3 content
     */
    public function testValidateWithValidNSEC3Content(): void
    {
        $result = $this->validator->validate(
            '1 0 10 AB12CD 01234ABCDEF A NS SOA MX TXT AAAA',  // content
            'hash.example.com',                                 // name
            '',                                                 // prio (empty as not used for NSEC3)
            3600,                                               // ttl
            86400                                               // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 10 AB12CD 01234ABCDEF A NS SOA MX TXT AAAA', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
        $this->assertEquals('hash.example.com', $data['name']);
    }

    /**
     * Test validation with minimum required fields (no type maps)
     */
    public function testValidateWithMinimumRequiredFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH',                        // content (no type maps)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 5 - AB12CD34EF56GH', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                                              // empty content
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with too few fields
     */
    public function testValidateWithTooFewFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 -',                                       // missing next hashed owner
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain at least', $result->getFirstError());
    }

    /**
     * Test validation with invalid hash algorithm
     */
    public function testValidateWithInvalidAlgorithm(): void
    {
        $result = $this->validator->validate(
            '2 0 5 - AB12CD34EF56GH',                        // invalid algorithm (2)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hash algorithm must be 1', $result->getFirstError());
    }

    /**
     * Test validation with invalid flags
     */
    public function testValidateWithInvalidFlags(): void
    {
        $result = $this->validator->validate(
            '1 2 5 - AB12CD34EF56GH',                        // invalid flags (2)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('flags must be 0 or 1', $result->getFirstError());
    }

    /**
     * Test validation with too many iterations
     */
    public function testValidateWithTooManyIterations(): void
    {
        $result = $this->validator->validate(
            '1 0 3000 - AB12CD34EF56GH',                     // too many iterations
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('iterations must be between', $result->getFirstError());
    }

    /**
     * Test validation with invalid salt
     */
    public function testValidateWithInvalidSalt(): void
    {
        $result = $this->validator->validate(
            '1 0 5 GZ AB12CD34EF56GH',                       // invalid salt (non-hex)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('salt must be -', $result->getFirstError());
    }

    /**
     * Test validation with invalid next hashed owner
     */
    public function testValidateWithInvalidNextHashedOwner(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - %%%INVALID%%%',                         // invalid next hashed owner
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('next hashed owner name', $result->getFirstError());
    }

    /**
     * Test validation with invalid record type in the type map
     */
    public function testValidateWithInvalidRecordType(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS INVALID-TYPE',      // invalid type
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
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
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH 1 2 6 15 16 28',         // numeric type codes
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 5 - AB12CD34EF56GH 1 2 6 15 16 28', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        // Set up TTL validator to fail
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with(-1, 86400)
            ->willReturn(ValidationResult::failure('TTL must be a positive number'));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS SOA',               // content
            'hash.example.com',                               // name
            '',                                               // prio
            -1,                                               // invalid ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        // Set up TTL validator to return default TTL
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with('', 86400)
            ->willReturn(ValidationResult::success(86400));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS SOA',               // content
            'hash.example.com',                               // name
            '',                                               // prio
            '',                                               // empty ttl, should use default
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 5 - AB12CD34EF56GH A NS SOA', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with invalid hostname
     */
    public function testValidateWithInvalidHostname(): void
    {
        // Set up hostname validator to fail
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->once())
            ->method('validate')
            ->with('invalid..hostname', true)
            ->willReturn(ValidationResult::failure('Invalid hostname format'));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH',                        // content
            'invalid..hostname',                              // invalid name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }
}
