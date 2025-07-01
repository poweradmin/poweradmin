<?php

namespace unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class DynamicDnsValidationServiceTest extends TestCase
{
    private DynamicDnsValidationService $validationService;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->validationService = new DynamicDnsValidationService($this->config);
    }

    public function testValidateRequestWithValidData(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            'example.com',
            '192.168.1.1',
            '2001:db8::1',
            false,
            'TestAgent/1.0'
        );

        $result = $this->validationService->validateRequest($request);
        $this->assertTrue($result->isValid());
    }

    public function testValidateRequestMissingUserAgent(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            ''
        );

        $result = $this->validationService->validateRequest($request);
        $this->assertFalse($result->isValid());
        $this->assertContains('User agent is required', $result->getErrors());
    }

    public function testValidateRequestMissingUsername(): void
    {
        $request = new DynamicDnsRequest(
            '',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $result = $this->validationService->validateRequest($request);
        $this->assertFalse($result->isValid());
        $this->assertContains('Username is required', $result->getErrors());
    }

    public function testValidateRequestMissingHostname(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            '',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $result = $this->validationService->validateRequest($request);
        $this->assertFalse($result->isValid());
        $this->assertContains('Hostname is required', $result->getErrors());
    }

    public function testValidateRequestMissingIpAddresses(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            'example.com',
            '',
            '',
            false,
            'TestAgent/1.0'
        );

        $result = $this->validationService->validateRequest($request);
        $this->assertFalse($result->isValid());
        $this->assertContains('At least one IP address is required', $result->getErrors());
    }

    public function testValidateHostnameValid(): void
    {
        $result = $this->validationService->validateHostname('example.com');
        $this->assertTrue($result->isValid());
    }

    public function testValidateHostnameInvalid(): void
    {
        $result = $this->validationService->validateHostname('invalid..hostname');
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateIpAddressesValidIPv4(): void
    {
        $result = $this->validationService->validateIpAddresses('192.168.1.1', '');
        $this->assertTrue($result->isValid());
    }

    public function testValidateIpAddressesValidIPv6(): void
    {
        $result = $this->validationService->validateIpAddresses('', '2001:db8::1');
        $this->assertTrue($result->isValid());
    }

    public function testValidateIpAddressesValidBoth(): void
    {
        $result = $this->validationService->validateIpAddresses('192.168.1.1', '2001:db8::1');
        $this->assertTrue($result->isValid());
    }

    public function testValidateIpAddressesEmpty(): void
    {
        $result = $this->validationService->validateIpAddresses('', '');
        $this->assertFalse($result->isValid());
        $this->assertContains('At least one valid IP address is required', $result->getErrors());
    }

    public function testValidateIpAddressesInvalid(): void
    {
        $result = $this->validationService->validateIpAddresses('invalid.ip', 'invalid::ip');
        $this->assertFalse($result->isValid());
        $this->assertContains('At least one valid IP address is required', $result->getErrors());
    }

    public function testCreateValidatedHostname(): void
    {
        $hostname = $this->validationService->createValidatedHostname('example.com');
        $this->assertEquals('example.com', $hostname->getValue());
    }

    public function testCreateValidatedHostnameInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validationService->createValidatedHostname('');
    }

    public function testCreateValidatedIpList(): void
    {
        $ipList = $this->validationService->createValidatedIpList('192.168.1.1', '2001:db8::1');
        $this->assertTrue($ipList->hasIpv4Addresses());
        $this->assertTrue($ipList->hasIpv6Addresses());
        $this->assertEquals(['192.168.1.1'], $ipList->getIpv4Addresses());
        $this->assertEquals(['2001:db8::1'], $ipList->getIpv6Addresses());
    }

    public function testCreateValidatedIpListInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validationService->createValidatedIpList('', '');
    }

    public function testValidateRequestMultipleErrors(): void
    {
        $request = new DynamicDnsRequest('', '', '', '', '', false, '');

        $result = $this->validationService->validateRequest($request);
        $this->assertFalse($result->isValid());

        $errors = $result->getErrors();
        $this->assertContains('User agent is required', $errors);
        $this->assertContains('Username is required', $errors);
        $this->assertContains('Hostname is required', $errors);
        $this->assertContains('At least one IP address is required', $errors);
    }
}
