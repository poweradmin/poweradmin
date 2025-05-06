<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\RKEYRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for RKEYRecordValidator using ValidationResult pattern
 */
class RKEYRecordValidatorResultTest extends TestCase
{
    private RKEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new RKEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A5sRv7cSsPQKPIwxLpyCrbJ4mr2L0EPOdvP6z6YfljK2ZmTbogU9aSU2fiq/4wjxbdkLyoDVgtO+JsxNN4bjr4WcWhsmk1Hg93FV9ZpkWb0Tbad8DFqNDzr//kZ';
        $name = 'rkey.example.com';
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

    public function testValidateWithInvalidName()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A5sRv7cSsPQKPIwxLpyCrbJ4mr2L0EPOdvP6z6YfljK2ZmTbogU9aSU2fiq/4wjxbdkLyoDVgtO+JsxNN4bjr4WcWhsmk1Hg93FV9ZpkWb0Tbad8DFqNDzr//kZ';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidContentCharacters()
    {
        $content = "257 3 5 AwEAAa\x01HI\x02wpx3w4VHKi6i1LHnTa"; // Non-printable characters in content
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateWithEmptyContent()
    {
        $content = ''; // Empty content
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateWithMissingComponents()
    {
        $content = '257 3'; // Missing algorithm and public key
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('must contain flags, protocol, algorithm, and public key data', $result->getFirstError());
    }

    public function testValidateWithInvalidFlags()
    {
        $content = 'abc 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A'; // Non-numeric flags
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags field must be a numeric value', $result->getFirstError());
    }

    public function testValidateWithInvalidProtocol()
    {
        $content = '257 abc 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A'; // Non-numeric protocol
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('protocol field must be a numeric value', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '257 3 abc AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A'; // Non-numeric algorithm
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('algorithm field must be a numeric value', $result->getFirstError());
    }

    public function testValidateWithEmptyPublicKey()
    {
        $content = '257 3 5 '; // Empty public key
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('must contain flags, protocol, algorithm, and public key data', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A';
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A';
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNonzeroUnusedPriority()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A';
        $name = 'rkey.example.com';
        $prio = 10; // Non-zero priority, but RKEY ignores this
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Priority should be set to 0 regardless of input
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithMultiPartPublicKey()
    {
        $content = '257 3 5 AwEAAaHIwpx3w4VHKi6i1LHnTaWeHCL154Jug0Rtc9ji5qwPXpBo6A 5sRv7cSsPQKPIwxLpyCrbJ4mr2L0EPOdvP6z6YfljK2ZmTbogU9aSU'; // Multi-part public key
        $name = 'rkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $this->assertEquals($content, $result->getData()['content']);
    }
}
