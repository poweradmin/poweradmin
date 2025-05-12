<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TLSARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the TLSARecordValidator
 */
class TLSARecordValidatorTest extends TestCase
{
    private TLSARecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new TLSARecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithDifferentUsageValue()
    {
        $content = '0 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDifferentSelectorValue()
    {
        $content = '3 0 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithSHA512MatchingType()
    {
        // SHA-512 must be exactly 128 hex characters long
        $content = '3 1 2 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithNonStandardHostname()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = 'www.example.com';  // Non-standard TLSA hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // Should still validate but with a warning
        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($name, $data['name']);
    }

    public function testValidateWithInvalidUsage()
    {
        $content = '4 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('usage field', $result->getFirstError());
    }

    public function testValidateWithInvalidSelector()
    {
        $content = '3 2 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('selector field', $result->getFirstError());
    }

    public function testValidateWithInvalidMatchingType()
    {
        $content = '3 1 3 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('matching type field', $result->getFirstError());
    }

    public function testValidateWithInvalidCertificateData()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74g';  // Invalid hex (g)
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('certificate data', $result->getFirstError());
    }

    public function testValidateWithInvalidSHA256Length()
    {
        $content = '3 1 1 a0b9b16969';  // Too short for SHA-256
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SHA-256', $result->getFirstError());
    }

    public function testValidateWithInvalidSHA512Length()
    {
        $content = '3 1 2 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';  // Too short for SHA-512
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SHA-512', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '3 1';  // Missing matching-type and certificate-data
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 10;  // TLSA records should have priority 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = '_443._tcp.www.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
