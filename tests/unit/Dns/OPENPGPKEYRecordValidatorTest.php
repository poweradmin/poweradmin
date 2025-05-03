<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\OPENPGPKEYRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the OPENPGPKEYRecordValidator
 */
class OPENPGPKEYRecordValidatorTest extends TestCase
{
    private OPENPGPKEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new OPENPGPKEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        // This is a sample base64 encoded data - not an actual PGP key
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
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

    public function testValidateWithRegularHostname()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'pgp.example.com';  // Regular hostname without hash format
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($name, $result['name']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidBase64Content()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyi!FbFBKT4TzXcVB';  // Contains invalid ! character
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
