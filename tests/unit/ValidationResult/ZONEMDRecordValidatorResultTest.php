<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\ZONEMDRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for ZONEMDRecordValidator using ValidationResult pattern
 */
class ZONEMDRecordValidatorResultTest extends TestCase
{
    private ZONEMDRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new ZONEMDRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidNameCharacters()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6';
        $name = "example\x01\x02.com"; // Non-printable characters in name
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateWithMissingComponents()
    {
        $content = '2018031900 1 1'; // Missing digest
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD record must contain serial, scheme, hash-algorithm, and digest', $result->getFirstError());
    }

    public function testValidateWithInvalidSerial()
    {
        $content = '-1 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Negative serial
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD serial must be a number between 0 and 4294967295', $result->getFirstError());
    }

    public function testValidateWithOverflowSerial()
    {
        $content = '4294967296 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Too large serial
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD serial must be a number between 0 and 4294967295', $result->getFirstError());
    }

    public function testValidateWithInvalidScheme()
    {
        $content = '2018031900 256 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Invalid scheme
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD scheme must be a number between 0 and 255', $result->getFirstError());
    }

    public function testValidateWithNonStandardScheme()
    {
        $content = '2018031900 2 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Non-standard scheme
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // This should still be valid, but with a warning in the errors
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHashAlgorithm()
    {
        $content = '2018031900 1 256 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Invalid hash algorithm
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD hash algorithm must be a number between 0 and 255', $result->getFirstError());
    }

    public function testValidateWithNonStandardHashAlgorithm()
    {
        $content = '2018031900 1 3 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6'; // Non-standard hash algorithm
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // This should still be valid, but with a warning
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithInvalidDigest()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102XZ'; // Contains invalid hex chars
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD digest must be a hexadecimal string', $result->getFirstError());
    }

    public function testValidateWithEmptyDigest()
    {
        $content = '2018031900 1 1 '; // Empty digest
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('ZONEMD record must contain serial, scheme, hash-algorithm, and digest', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6';
        $name = 'example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNonzeroUnusedPriority()
    {
        $content = '2018031900 1 1 48E31533D8202A584FFC32D1E71D0C7FB9849F9B47759F354B708130F6A5C1C79240AA5752F793916F4AD3C73F102B6';
        $name = 'example.com';
        $prio = 10; // Non-zero priority, but ZONEMD ignores this
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Priority should be set to 0 regardless of input
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithSHA512Algorithm()
    {
        $content = '2018031900 1 2 a5a6a4ed38dfb14a3ad3f8c4dbb5631d5eb6905380ccf56ac345de233c449b70e2ec326392bfa6e8b60fa56e1b0b0fd2fc3bd7f953fa6494f55c9a7618e86fc1'; // SHA-512
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }
}
