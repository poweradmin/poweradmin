<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

class KXRecordValidatorTest extends BaseDnsTest
{
    private KXRecordValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return null;
            });
        $this->validator = new KXRecordValidator($configMock);
    }

    public function testValidKXRecord()
    {
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($prio, $data['prio']);
        $this->assertEquals($ttl, $data['ttl']);
    }

    public function testInvalidKeyExchanger()
    {
        $content = '-invalid-.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testInvalidDomainName()
    {
        $content = 'kx.example.com';
        $name = '-invalid-.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testInvalidPriority()
    {
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 65536; // Invalid priority (> 65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testDefaultPriority()
    {
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = ''; // Empty priority should default to 10
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    public function testInvalidTTL()
    {
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = -1; // Invalid negative TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testDefaultTTL()
    {
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($defaultTTL, $data['ttl']);
    }

    public function testValidatePrivateMethods()
    {
        // Test validatePriority with reflection to access private method
        $reflectionMethod = new ReflectionMethod(KXRecordValidator::class, 'validatePriority');
        $reflectionMethod->setAccessible(true);

        // Valid priority
        $result = $reflectionMethod->invoke($this->validator, 10);
        $this->assertTrue($result->isValid());
        $this->assertEquals(10, $result->getData());

        // Empty priority (should default to 10)
        $result = $reflectionMethod->invoke($this->validator, '');
        $this->assertTrue($result->isValid());
        $this->assertEquals(10, $result->getData());

        // Invalid priority (too large)
        $result = $reflectionMethod->invoke($this->validator, 65536);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());

        // Invalid priority (non-numeric)
        $result = $reflectionMethod->invoke($this->validator, 'invalid');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }
}
