<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SVCBRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the SVCBRecordValidator
 */
class SVCBRecordValidatorTest extends TestCase
{
    private SVCBRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new SVCBRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '1 . alpn=h2,h3 ipv4hint=192.0.2.1 ipv6hint=2001:db8::1';
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(1, $result['prio']); // Priority from content
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithExplicitPriority()
    {
        $content = '1 . alpn=h2,h3';
        $name = 'svcb.example.com';
        $prio = 20; // Explicit priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(20, $result['prio']); // Should use explicit priority
    }

    public function testValidateWithMinimalValidRecord()
    {
        $content = '0 svc.example.com';
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals(0, $result['prio']);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'svc.example.com'; // Missing priority
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '70000 . alpn=h2'; // Priority > 65535
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTarget()
    {
        $content = '1 @#$ alpn=h2'; // Invalid target
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidIpv4Hint()
    {
        $content = '1 . ipv4hint=999.999.999.999'; // Invalid IPv4
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidIpv6Hint()
    {
        $content = '1 . ipv6hint=2001:xyz::1'; // Invalid IPv6
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPort()
    {
        $content = '1 . port=99999'; // Port > 65535
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '1 . alpn=h2';
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '1 . alpn=h2';
        $name = 'svcb.example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithValidParameterVariations()
    {
        $validContents = [
            '1 . alpn=h2,h3',
            '1 . alpn=h2,h3 port=443',
            '1 . ipv4hint=192.0.2.1,192.0.2.2',
            '1 . ipv6hint=2001:db8::1,2001:db8::2',
            '1 . ech=base64encodeddata',
            '1 . alpn=h2,h3 port=443 ipv4hint=192.0.2.1 ipv6hint=2001:db8::1',
        ];

        foreach ($validContents as $content) {
            $name = 'svcb.example.com';
            $prio = '';
            $ttl = 3600;
            $defaultTTL = 86400;

            $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

            $this->assertIsArray($result, "SVCB format should be valid: $content");
            $this->assertEquals($content, $result['content']);
        }
    }
}
