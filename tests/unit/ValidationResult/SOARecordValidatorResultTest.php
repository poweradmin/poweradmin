<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for SOARecordValidator using ValidationResult pattern
 */
class SOARecordValidatorResultTest extends TestCase
{
    private SOARecordValidator $validator;
    private ConfigurationManager $configMock;
    private PDOLayer $dbMock;
    private Validator $validatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->dbMock = $this->createMock(PDOLayer::class);

        // Create a partial mock of the Validator class
        $this->validatorMock = $this->createPartialMock(Validator::class, ['is_valid_email']);
        $this->validatorMock->method('is_valid_email')
            ->willReturnCallback(function ($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });

        // Set up reflection to replace validator in SOARecordValidator
        $this->validator = new SOARecordValidator($this->configMock, $this->dbMock);
        $this->validator->setSOAParams('hostmaster@example.com', 'example.com');
    }

    public function testValidateWithValidData()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertStringContainsString('ns1.example.com', $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // SOA records always have 0 priority
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithoutSettingSOAParams()
    {
        // Create a new validator without setting SOA params
        $validator = new SOARecordValidator($this->configMock, $this->dbMock);

        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('SOA validation parameters not set', $result->getFirstError());
    }

    public function testValidateWithInvalidName()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = 'subdomain.example.com'; // Invalid - should be the exact zone name
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for name field', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = '-invalid-example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Update SOA params to match the invalid name
        $this->validator->setSOAParams('hostmaster@example.com', '-invalid-example.com');

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithTooManySOAFields()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600 extra'; // Extra field
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('SOA record must have between 1 and 7 fields', $result->getFirstError());
    }

    public function testValidateWithTooFewSOAFields()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101'; // Missing refresh, retry, expire, minimum
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('SOA record must have exactly 7 fields', $result->getFirstError());
    }

    public function testValidateWithInvalidPrimaryNameserver()
    {
        $content = '-invalid-ns.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600'; // Invalid primary NS
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid primary nameserver', $result->getFirstError());
    }

    public function testValidateWithInvalidEmail()
    {
        $content = 'ns1.example.com invalid-email 2023010101 3600 1800 604800 3600'; // Invalid email
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid email address', $result->getFirstError());
    }

    public function testValidateWithNonNumericSerial()
    {
        $content = 'ns1.example.com hostmaster.example.com abc 3600 1800 604800 3600'; // Non-numeric serial
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Serial number must be numeric', $result->getFirstError());
    }

    public function testValidateWithNonNumericTimingFields()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 abc 1800 604800 3600'; // Non-numeric refresh
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('SOA timing fields', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 604800 3600';
        $name = 'example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithDottedEmail()
    {
        $content = 'ns1.example.com hostmaster\.admin.example.com 2023010101 3600 1800 604800 3600'; // Email with dot notation
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertStringContainsString('hostmaster\.admin.example.com', $data['content']);
    }
}
