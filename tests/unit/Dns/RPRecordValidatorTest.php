<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\RPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

/**
 * Tests for the RPRecordValidator
 */
class RPRecordValidatorTest extends TestCase
{
    private RPRecordValidator $validator;
    private ConfigurationManager $configMock;
    private HostnameValidator $hostnameValidatorMock;
    private TTLValidator $ttlValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Create mock validators
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if (strpos($hostname, 'invalid hostname') !== false) {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::success(['hostname' => $hostname]);
            });

        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->method('validate')
            ->willReturnCallback(function ($ttl, $defaultTTL) {
                if ($ttl === -1) {
                    return ValidationResult::failure('Invalid TTL value');
                }
                if (empty($ttl)) {
                    return ValidationResult::success($defaultTTL);
                }
                return ValidationResult::success($ttl);
            });

        // Create the validator and inject mocks
        $this->validator = new RPRecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(RPRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(RPRecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'admin.example.com. info.example.com.';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithDotForMailbox()
    {
        $content = '. info.example.com.';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDotForTxtDomain()
    {
        $content = 'admin.example.com. .';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithBothDotsForNone()
    {
        $content = '. .';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    public function testValidateWithMissingTxtDomain()
    {
        $content = 'admin.example.com.';  // Missing TXT domain
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidMailboxDomain()
    {
        $content = 'admin@example.com. info.example.com.';  // Invalid mailbox domain
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('invalid', strtolower($result->getFirstError()));
    }

    public function testValidateWithInvalidTxtDomain()
    {
        $content = 'admin.example.com. invalid_domain!';  // Invalid TXT domain
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TXT domain', $result->getFirstError());
    }

    public function testValidateWithNonFqdnMailboxDomain()
    {
        $content = 'admin.example.com info.example.com.';  // Missing dot at end of first domain
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('fully qualified domain name', $result->getFirstError());
    }

    public function testValidateWithNonFqdnTxtDomain()
    {
        $content = 'admin.example.com. info.example.com';  // Missing dot at end of second domain
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('fully qualified domain name', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'admin.example.com. info.example.com.';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'admin.example.com. info.example.com.';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'admin.example.com. info.example.com.';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'admin.example.com. info.example.com.';
        $name = 'example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }
}
