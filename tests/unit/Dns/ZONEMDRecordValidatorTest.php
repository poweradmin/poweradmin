<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\ZONEMDRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the ZONEMDRecordValidator
 */
class ZONEMDRecordValidatorTest extends TestCase
{
    private ZONEMDRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new ZONEMDRecordValidator($this->configMock);
    }

    public function testValidateWithValidSHA384Data()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
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

        // Check warnings are present
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Should have a warning about placement at zone apex
        $foundApexWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'apex') !== false) {
                $foundApexWarning = true;
                break;
            }
        }
        $this->assertTrue($foundApexWarning, 'Warning about zone apex placement should be present');

        // Should have a warning about SHA-384 being recommended
        $foundSHA384Warning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'SHA-384') !== false && strpos($warning, 'recommended') !== false) {
                $foundSHA384Warning = true;
                break;
            }
        }
        $this->assertTrue($foundSHA384Warning, 'Warning about SHA-384 being recommended should be present');
    }

    public function testValidateWithValidSHA512Data()
    {
        // SHA-512 must be exactly 128 hex characters
        $content = '2021121600 1 2 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithInvalidSerial()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = 'invalid 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithTooLargeSerial()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '9999999999 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidScheme()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 999 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHashAlgorithm()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 999 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }


    public function testValidateWithInvalidDigestHex()
    {
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4ez'; // 'z' is not hex
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '2021121600 1';  // Missing hash-algorithm and digest
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }


    public function testValidateWithInvalidTTL()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidDigestLength()
    {
        // SHA-384 requires exactly 96 hex characters, but we're providing only 94
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('96 hexadecimal characters', $result->getFirstError());
    }

    public function testValidateWithTooShortDigest()
    {
        // RFC 8976 requires a minimum of 24 hex characters (12 octets)
        $content = '2021121600 1 1 a0b9b16969';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('at least 24 hexadecimal characters', $result->getFirstError());
    }

    public function testValidateWithPrivateUseScheme()
    {
        // Scheme 240-255 are reserved for private use
        $content = '2021121600 240 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        // Should have a warning about private use scheme
        $data = $result->getData();
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'private use') !== false && strpos($warning, 'scheme') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWarning, 'Warning about private use scheme should be present');
    }

    public function testValidateWithPrivateUseHashAlgorithm()
    {
        // Hash algorithm 240-255 are reserved for private use
        $content = '2021121600 1 240 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        // Should have a warning about private use hash algorithm
        $data = $result->getData();
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'private use') !== false && strpos($warning, 'hash algorithm') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWarning, 'Warning about private use hash algorithm should be present');
    }

    public function testValidateWithReservedScheme()
    {
        // Scheme 0 is reserved and not for use
        $content = '2021121600 0 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('scheme must be 1', $result->getFirstError());
    }
}
