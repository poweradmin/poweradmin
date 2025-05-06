<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for LOCRecordValidator using ValidationResult pattern
 */
class LOCRecordValidatorResultTest extends TestCase
{
    private LOCRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new LOCRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = 'loc.example.com';
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

    public function testValidateWithInvalidLocationFormat()
    {
        $content = 'invalid format'; // Invalid LOC format
        $name = 'loc.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid LOC record format', $result->getFirstError());
    }

    public function testValidateWithInvalidLatitude()
    {
        $content = '95 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m'; // Invalid latitude (>90)
        $name = 'loc.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = 'loc.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = 'loc.example.com';
        $prio = 10; // Invalid priority for LOC record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Priority field for LOC records', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = 'loc.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '40 30 20.000 N 75 10 40.000 W 10m 20m 2000m 20m';
        $name = 'loc.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithDifferentFormat()
    {
        $content = '52 22 23.000 N 4 53 32.000 E 0m 2m 10m 10m'; // Different but valid format
        $name = 'loc.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }
}
