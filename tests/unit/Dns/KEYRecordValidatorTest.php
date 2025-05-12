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
use Poweradmin\Domain\Service\DnsValidation\KEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

/**
 * Tests for the KEYRecordValidator using ValidationResult
 */
class KEYRecordValidatorTest extends TestCase
{
    private KEYRecordValidator $validator;
    private ConfigurationManager $configMock;
    private HostnameValidator $hostnameValidatorMock;
    private TTLValidator $ttlValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Mock the validators we need
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if (strpos($hostname, '-invalid') !== false) {
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

        // Create the validator instance
        $this->validator = new KEYRecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(KEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(KEYRecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    public function testValidateWithValidData()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
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

        // Check for obsolete status warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for RFC reference in warnings
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('RFC 4034', $warningText);
        $this->assertStringContainsString('DNSKEY', $warningText);
    }

    public function testValidateWithAnotherValidData()
    {
        // Use a Base64 key with spaces (which is valid per RFC 2535)
        $content = '256 3 5 AQPSKmynfzW4 kyBv015MUG2DeIQ3 Cbl+BBZH4b/0PY1k xkmvHjcZc8no';
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

        // Check for warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());
    }

    public function testValidateWithInvalidFlags()
    {
        $content = '65536 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Flags > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('flags must be', $result->getFirstError());
    }

    public function testValidateWithInvalidProtocol()
    {
        $content = '256 256 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Protocol > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('protocol must be', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '256 3 256 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Algorithm > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('algorithm must be', $result->getFirstError());
    }

    public function testValidateWithInvalidPublicKeyFormat()
    {
        // Test with invalid Base64 public key
        $content = '256 3 5 !@#$%^';  // Invalid Base64 characters
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Base64', $result->getFirstError());
    }

    public function testValidateWithRSAMD5Algorithm()
    {
        // Test with algorithm 1 (RSA/MD5) which should trigger a security warning
        $content = '256 3 1 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for algorithm-specific security warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('RSA/MD5', $warningText);
        $this->assertStringContainsString('weak', $warningText);
    }

    public function testValidateWithMissingPublicKey()
    {
        $content = '256 3 5'; // Missing public key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '256 3'; // Missing algorithm and public key
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
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // We don't check the exact error message as it comes from the hostname validator
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
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
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    public function testValidateWithDNSSECZoneKey()
    {
        // Test with flags=257 (zone key) and protocol=3 (DNSSEC)
        $content = '257 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for DNSSEC zone key specific warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('DNSKEY records should be used', $warningText);
        $this->assertStringContainsString('flags=257', $warningText);
    }

    public function testValidateWithUnusualProtocolValue()
    {
        // Test with unusual protocol value
        $content = '256 2 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for unusual protocol warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('Unusual protocol value', $warningText);
    }
}
