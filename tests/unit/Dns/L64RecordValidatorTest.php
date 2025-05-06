<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\L64RecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the L64RecordValidator
 */
class L64RecordValidatorTest extends TestCase
{
    private L64RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new L64RecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 2001:0db8:1140:1000';
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
        $this->assertEquals(0, $data['prio']); // Using provided prio value
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithProvidedPriority()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 20; // This should override the content's preference
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(20, $data['prio']); // Should use provided prio
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithAnotherValidLocator()
    {
        $content = '20 fedc:ba98:7654:3210';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '65536 2001:0db8:1140:1000'; // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidLocator()
    {
        $content = '10 2001:0db8:1140:GGGG'; // Invalid hex characters
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithIPv4AsLocator()
    {
        $content = '10 192.0.2.1'; // IPv4 not allowed for L64
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithWrongNumberOfSegments()
    {
        $content = '10 2001:0db8:1140'; // Not enough segments
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithTooManySegments()
    {
        $content = '10 2001:0db8:1140:1000:abcd'; // Too many segments
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10'; // Missing locator
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and locator64 separated by space', $result->getFirstError());
    }

    public function testValidateWithTooManyParts()
    {
        $content = '10 2001:0db8:1140:1000 extrapart'; // Too many parts
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and locator64 separated by space', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNegativePreference()
    {
        $content = '-1 2001:0db8:1140:1000'; // Negative preference not allowed
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }
}
