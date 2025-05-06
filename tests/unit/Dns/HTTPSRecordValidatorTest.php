<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HTTPSRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the HTTPSRecordValidator with ValidationResult pattern
 */
class HTTPSRecordValidatorTest extends TestCase
{
    private HTTPSRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new HTTPSRecordValidator($this->configMock);
    }

    public function testValidateWithValidTargetOnly()
    {
        $content = '1 example.org';  // Valid HTTPS with target only
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // Priority in content, not in prio field
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithValidAliasTarget()
    {
        $content = '0 .';  // Valid alias form using "."
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithValidParams()
    {
        $content = '1 example.org alpn=h2 ipv4hint=192.0.2.1';  // Valid with parameters
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '65536 example.org';  // Priority > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testValidateWithInvalidTarget()
    {
        $content = '1 -invalid-hostname.example.org';  // Invalid hostname
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('target', $result->getFirstError());
    }

    public function testValidateWithInvalidParams()
    {
        $content = '1 example.org alpn:h2';  // Invalid parameter format (using : instead of =)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('parameters', $result->getFirstError());
    }

    public function testValidateWithMissingTarget()
    {
        $content = '1';  // Missing target
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority and target', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '1 example.org';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithProvidedExternalPriority()
    {
        $content = '1 example.org';
        $name = 'host.example.com';
        $prio = 10;  // Priority should be in content, not provided separately
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field should not be used', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '1 example.org';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '1 example.org';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
