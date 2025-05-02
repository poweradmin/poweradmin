<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\AFSDBRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the AFSDBRecordValidator
 */
class AFSDBRecordValidatorTest extends TestCase
{
    private AFSDBRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new AFSDBRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1; // AFS cell database server
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(1, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithValidSubtypeTwoData()
    {
        $content = 'dce.example.com';
        $name = 'example.com';
        $prio = 2; // DCE authenticated name server
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(2, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '-invalid-hostname.example.com'; // Invalid hostname
        $name = 'example.com';
        $prio = 1;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidDomainName()
    {
        $content = 'valid.example.com';
        $name = '-invalid-domain.com'; // Invalid domain name
        $prio = 1;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSubtype()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 3; // Invalid subtype (only 1 and 2 are valid)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptySubtype()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = ''; // Empty subtype should default to 1
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['prio']); // Should default to 1
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
