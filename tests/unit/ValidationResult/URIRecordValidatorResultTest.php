<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\URIRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for URIRecordValidator using ValidationResult pattern
 */
class URIRecordValidatorResultTest extends TestCase
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
        $content = '10 1 "https://example.com/"';
        $name = "uri\x01\x02invalid.example.com"; // Non-printable characters in name
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateWithInvalidContentCharacters()
    {
        $content = "10 1 \"https://example.com/\x01\x02invalid\""; // Non-printable characters in content
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateWithInvalidURIFormat()
    {
        $content = '10 1 https://example.com/'; // Missing quotes around URI
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URI record must be in the format', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '65536 1 "https://example.com/"'; // Priority out of range
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URI priority must be between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidWeight()
    {
        $content = '10 65536 "https://example.com/"'; // Weight out of range
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URI weight must be between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidURI()
    {
        $content = '10 1 "example.com/"'; // Missing protocol
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URI must start with a valid protocol', $result->getFirstError());
    }

    public function testValidateWithMissingSlashes()
    {
        $content = '10 1 "http:example.com/"'; // Missing // after protocol
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URI with this protocol must include "://" after the protocol name', $result->getFirstError());
    }

    public function testValidateWithSpecialProtocol()
    {
        $content = '10 1 "mailto:user@example.com"'; // Special protocol doesn't need //
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithMultipleSpecialProtocols()
    {
        $protocols = ['mailto:', 'tel:', 'sms:', 'bitcoin:'];

        foreach ($protocols as $protocol) {
            $content = '10 1 "' . $protocol . 'example"';
            $name = 'uri.example.com';
            $prio = 0;
            $ttl = 3600;
            $defaultTTL = 86400;

            $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

            $this->assertTrue($result->isValid(), "Failed for protocol: $protocol");
            $this->assertEmpty($result->getErrors(), "Failed for protocol: $protocol");
        }
    }

    public function testValidateWithPriorityFromContent()
    {
        $content = '20 1 "https://example.com/"'; // Priority 20 in content
        $name = 'uri.example.com';
        $prio = null; // No priority specified, should use from content
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $this->assertEquals(20, $result->getData()['prio']); // Should extract 20 from content
    }

    public function testValidateWithPriorityOverride()
    {
        $content = '20 1 "https://example.com/"'; // Priority 20 in content
        $name = 'uri.example.com';
        $prio = 30; // Explicitly specified priority should override content
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $this->assertEquals(30, $result->getData()['prio']); // Should use 30 from prio parameter
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 1 "https://example.com/"';
        $name = 'uri.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
