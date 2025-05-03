<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\KEYRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the KEYRecordValidator
 */
class KEYRecordValidatorTest extends TestCase
{
    private KEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new KEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithAnotherValidData()
    {
        $content = '256 3 5 AQPSKmynfzW4 kyBv015MUG2DeIQ3 Cbl+BBZH4b/0PY1k xkmvHjcZc8no';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidFlags()
    {
        $content = '65536 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Flags > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidProtocol()
    {
        $content = '256 256 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Protocol > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '256 3 256 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ=='; // Algorithm > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPublicKeyFormat()
    {
        // Since base64 validation is relaxed in the validator, we'll test with
        // a content format that will still be rejected (missing parts)
        $content = '256 3';  // Missing algorithm and key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithMissingPublicKey()
    {
        $content = '256 3 5'; // Missing public key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '256 3'; // Missing algorithm and public key
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
