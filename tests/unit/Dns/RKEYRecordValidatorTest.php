<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\RKEYRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the RKEYRecordValidator
 */
class RKEYRecordValidatorTest extends TestCase
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
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
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

    public function testValidateWithDifferentFlagsValue()
    {
        $content = '257 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithDifferentProtocolValue()
    {
        $content = '256 2 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithDifferentAlgorithmValue()
    {
        $content = '256 3 5 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
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

    public function testValidateWithInvalidFlags()
    {
        $content = 'invalid 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidProtocol()
    {
        $content = '256 invalid 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '256 3 invalid AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithMissingPublicKeyData()
    {
        $content = '256 3 7';  // Missing public key data
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
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
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }
}
