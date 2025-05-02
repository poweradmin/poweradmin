<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for SRV record validation
 */
class SrvValidationTest extends TestCase
{
    private SRVRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new SRVRecordValidator($this->configMock);
    }

    /**
     * Data provider for SRV name tests
     */
    public static function srvNameProvider(): array
    {
        return [
            'valid basic srv name' => ['_sip._tcp.example.com', true],
            'valid with hyphen in service' => ['_xmpp-server._tcp.example.com', true],
            'valid with subdomain' => ['_sip._tcp.sub.example.com', true],
            'valid with uppercase service' => ['_SIP._tcp.example.com', true],
            'invalid: missing first underscore' => ['sip._tcp.example.com', false],
            'invalid: missing second underscore' => ['_sip.tcp.example.com', false],
            'invalid: missing domain part' => ['_sip._tcp', false],
            'invalid: too long name' => [str_repeat('a', 256), false],
            'invalid: invalid chars in service' => ['_sip@bad._tcp.example.com', false],
            'invalid: too few segments' => ['_sip.example.com', false]
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('srvNameProvider')]
    public function testIsValidSrvName(string $name, bool $expected)
    {
        $result = $this->validator->validate('10 20 5060 sip.example.com', $name, 0, 3600, 86400);

        if ($expected) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertEquals($name, $result['name']);
        } else {
            $this->assertFalse($result);
        }
    }

    /**
     * Data provider for SRV content tests
     */
    public static function srvContentProvider(): array
    {
        return [
            'valid basic SRV content' => ['10 20 5060 sip.example.com', '_sip._tcp.example.com', true],
            'valid with zero priority and weight' => ['0 0 443 example.com', '_https._tcp.example.com', true],
            'valid with dot as target' => ['0 0 443 .', '_https._tcp.example.com', true],
            'valid with max values' => ['65535 65535 65535 example.com', '_sip._tcp.example.com', true],
            'invalid: priority not a number' => ['a 20 5060 sip.example.com', '_sip._tcp.example.com', false],
            'invalid: weight not a number' => ['10 b 5060 sip.example.com', '_sip._tcp.example.com', false],
            'invalid: port not a number' => ['10 20 port sip.example.com', '_sip._tcp.example.com', false],
            'invalid: invalid hostname' => ['10 20 5060 @invalid!hostname', '_sip._tcp.example.com', false],
            'invalid: priority too high' => ['70000 20 5060 sip.example.com', '_sip._tcp.example.com', false],
            'invalid: weight too high' => ['10 70000 5060 sip.example.com', '_sip._tcp.example.com', false],
            'invalid: port too high' => ['10 20 70000 sip.example.com', '_sip._tcp.example.com', false],
            'invalid: too few fields' => ['10 20 example.com', '_sip._tcp.example.com', false],
            'invalid: empty target' => ['10 20 5060 ', '_sip._tcp.example.com', false]
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('srvContentProvider')]
    public function testIsValidSrvContent(string $content, string $name, bool $expected)
    {
        $result = $this->validator->validate($content, $name, 0, 3600, 86400);

        if ($expected) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('content', $result);
            $this->assertEquals($content, $result['content']);
        } else {
            $this->assertFalse($result);
        }
    }

    public function testValidateWithCustomPriority()
    {
        $content = "20 10 5060 sip.example.com";
        $name = "_sip._tcp.example.com";
        $prio = 20;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(20, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithDefaultPriority()
    {
        $content = "10 10 5060 sip.example.com";
        $name = "_sip._tcp.example.com";
        $prio = "";  // Empty priority should default to 10
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(10, $result['prio']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = "10 10 5060 sip.example.com";
        $name = "_sip._tcp.example.com";
        $prio = 70000;  // Priority too high
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = "10 10 5060 sip.example.com";
        $name = "_sip._tcp.example.com";
        $prio = 10;
        $ttl = "";  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
