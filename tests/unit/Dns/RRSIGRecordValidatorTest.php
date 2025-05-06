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
use Poweradmin\Domain\Service\DnsValidation\RRSIGRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

/**
 * Tests for the RRSIGRecordValidator
 */
class RRSIGRecordValidatorTest extends TestCase
{
    private RRSIGRecordValidator $validator;
    private ConfigurationManager $configMock;
    private HostnameValidator $hostnameValidatorMock;
    private TTLValidator $ttlValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Create mock validators
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if (strpos($hostname, 'invalid hostname') !== false) {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::success(['hostname' => $hostname]);
            });

        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->method('validate')
            ->willReturnCallback(function ($ttl, $defaultTTL) {
                if ($ttl === -1) {
                    return ValidationResult::failure('Invalid TTL value');
                }
                if (empty($ttl)) {
                    return ValidationResult::success($defaultTTL);
                }
                return ValidationResult::success($ttl);
            });

        // Create the validator and inject mocks
        $this->validator = new RRSIGRecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(RRSIGRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(RRSIGRecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
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
    }

    public function testValidateWithDifferentRecordType()
    {
        $content = 'MX 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDifferentAlgorithm()
    {
        $content = 'A 13 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    public function testValidateWithInvalidCoveredType()
    {
        $content = 'INVALID 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('covered type', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = 'A invalid 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('algorithm', $result->getFirstError());
    }

    public function testValidateWithInvalidLabels()
    {
        $content = 'A 8 invalid 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('labels', $result->getFirstError());
    }

    public function testValidateWithInvalidOrigTTL()
    {
        $content = 'A 8 2 invalid 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidExpiration()
    {
        $content = 'A 8 2 86400 invalid 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('expiration', $result->getFirstError());
    }

    public function testValidateWithInvalidInception()
    {
        $content = 'A 8 2 86400 20230515130000 invalid 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('inception', $result->getFirstError());
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 invalid example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('key tag', $result->getFirstError());
    }

    public function testValidateWithInvalidSignerName()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('signer name', $result->getFirstError());
    }

    public function testValidateWithMissingSignature()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com.';  // Missing signature
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }
}
