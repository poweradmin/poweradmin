<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DNAMERecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DNAMERecordValidator with ValidationResult pattern
 */
class DNAMERecordValidatorTest extends TestCase
{
    private DNAMERecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DNAMERecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTarget()
    {
        $content = '-invalid-target.example.com';  // Invalid target hostname
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('DNAME target', $result->getFirstError());
    }

    public function testValidateWithSelfReference()
    {
        $content = 'source.example.com';  // Same as name (self-reference)
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot point to itself', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 10;  // Invalid priority for DNAME
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for DNAME records', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
