<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\MINFORecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class MINFORecordValidatorTest extends BaseDnsTest
{
    private MINFORecordValidator $validator;

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
        $this->validator = new MINFORecordValidator($configMock);
    }

    public function testValidMINFORecord()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null; // MINFO doesn't use priority
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

        $this->assertEquals(0, $data['prio']); // MINFO sets priority to 0

        $this->assertEquals($ttl, $data['ttl']);
    }

    public function testInvalidContent()
    {
        $content = 'responsible.example.com'; // Missing error mailbox
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidResponsibleMailbox()
    {
        $content = '-invalid-.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidErrorMailbox()
    {
        $content = 'responsible.example.com -invalid-.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidDomainName()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = '-invalid-.example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidTTL()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = -1; // Invalid negative TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testDefaultTTL()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $data = $result->getData();

        $this->assertEquals($defaultTTL, $data['ttl']);
    }
}
