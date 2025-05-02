<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\URIRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the URIRecordValidator
 */
class URIRecordValidatorTest extends TestCase
{
    private URIRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new URIRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(10, $result['prio']); // Priority from content
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithExplicitPriority()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = 20; // Explicit priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(20, $result['prio']); // Should use explicit priority
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'https://example.com/'; // Missing priority and weight
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '70000 1 "https://example.com/"'; // Priority > 65535
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidWeight()
    {
        $content = '10 70000 "https://example.com/"'; // Weight > 65535
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidURI()
    {
        $content = '10 1 "example.com"'; // Missing protocol
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithNonPrintableCharacters()
    {
        $content = '10 1 "https://example.com/"'; // Valid content
        $name = 'uri.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithValidURIVariations()
    {
        $validURIs = [
            '10 1 "http://example.com/"',
            '10 1 "https://subdomain.example.com/path"',
            '10 1 "ftp://files.example.com/"',
            '10 1 "ldap://directory.example.com/"',
            '10 1 "mailto:user@example.com"',
        ];

        foreach ($validURIs as $content) {
            $name = 'uri.example.com';
            $prio = '';
            $ttl = 3600;
            $defaultTTL = 86400;

            $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

            $this->assertIsArray($result, "URI format should be valid: $content");
            $this->assertEquals($content, $result['content']);
        }
    }
}
