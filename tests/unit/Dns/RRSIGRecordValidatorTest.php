<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\RRSIGRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the RRSIGRecordValidator
 */
class RRSIGRecordValidatorTest extends TestCase
{
    private RRSIGRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new RRSIGRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
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

    public function testValidateWithDifferentRecordType()
    {
        $content = 'MX 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithDifferentAlgorithm()
    {
        $content = 'A 13 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidCoveredType()
    {
        $content = 'INVALID 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = 'A invalid 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidLabels()
    {
        $content = 'A 8 invalid 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidOrigTTL()
    {
        $content = 'A 8 2 invalid 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidExpiration()
    {
        $content = 'A 8 2 86400 invalid 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidInception()
    {
        $content = 'A 8 2 86400 20230515130000 invalid 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 invalid example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSignerName()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithMissingSignature()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com.';  // Missing signature
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHjkasdjfhsdkjfhskjdhfksdjhfkASDJASDHoiwehjroiwejhroiwejhroiwejroijewr+OIAJDOIAJSdoiajds9oia3j==';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }
}
