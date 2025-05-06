<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

class MXRecordValidatorTest extends BaseDnsTest
{
    private MXRecordValidator $validator;

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
                return 'example.com'; // Default value for tests from ValidationResultTest
            });
        $this->validator = new MXRecordValidator($configMock);
    }

    public function testValidateMXRecord()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($prio, $data['prio']);
        $this->assertEquals($ttl, $data['ttl']);
    }

    public function testInvalidMailServerHostname()
    {
        $content = '-invalid-.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
        $this->assertStringContainsString('Invalid mail server hostname', $result->getFirstError());
    }

    public function testInvalidDomainName()
    {
        $content = 'mail.example.com';
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
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 65536; // Invalid priority (> 65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testDefaultPriority()
    {
        $content = 'mail.example.com';
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
        $content = 'mail.example.com';
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
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($defaultTTL, $data['ttl']);
    }

    public function testValidatePrivateMethods()
    {
        // Test validatePriority with reflection to access private method
        $reflectionMethod = new \ReflectionMethod(MXRecordValidator::class, 'validatePriority');
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

    // Additional tests from MXRecordValidatorResultTest

    public function testValidateWithNegativePriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = -1; // Invalid priority (negative)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithNonNumericPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 'abc'; // Invalid priority (non-numeric)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithLowPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 0; // Valid lowest priority according to RFC
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithHighPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 65535; // Valid highest priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(65535, $data['prio']);
    }

    public function testValidateWithStringTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = '3600'; // String TTL should be parsed correctly
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithStringPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = '20'; // String priority should be parsed correctly
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(20, $data['prio']);
        $this->assertIsInt($data['prio']);
    }
}
