<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\NSRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NSRecordValidatorTest extends BaseDnsTest
{
    private NSRecordValidator $validator;

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
        $this->validator = new NSRecordValidator($configMock);
    }

    public function testValidNSRecord()
    {
        $content = 'ns1.example.com';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals($name, $data['name']);
        $data = $result->getData();

        $this->assertEquals($prio, $data['prio']);

        $this->assertEquals($ttl, $data['ttl']);
    }

    public function testInvalidNameserver()
    {
        $content = '-invalid-.example.com';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidDomainName()
    {
        $content = 'ns1.example.com';
        $name = '-invalid-.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidPriority()
    {
        $content = 'ns1.example.com';
        $name = 'example.com';
        $prio = 10; // Invalid priority (should be 0)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testEmptyPriority()
    {
        $content = 'ns1.example.com';
        $name = 'example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $data = $result->getData();

        $this->assertEquals(0, $data['prio']);
    }

    public function testInvalidTTL()
    {
        $content = 'ns1.example.com';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1; // Invalid negative TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testDefaultTTL()
    {
        $content = 'ns1.example.com';
        $name = 'example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($defaultTTL, $data['ttl']);
    }
}
