<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the CNAMERecordValidator
 */
class CNAMERecordValidatorTest extends TestCase
{
    private CNAMERecordValidator $validator;
    private ConfigurationManager $configMock;
    private PDOLayer $dbMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->dbMock = $this->createMock(PDOLayer::class);

        $this->validator = new CNAMERecordValidator($this->configMock, $this->dbMock);
    }

    public function testValidateWithValidData()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $rid = 0;
        $zone = 'example.com';

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $rid, $zone);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithConflictingRecord()
    {
        // Mock DB response to indicate a conflicting record exists
        $this->dbMock->method('queryOne')
            ->willReturn(['id' => 1]); // Conflict found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already exists a record', $result->getFirstError());
    }

    public function testValidateWithInvalidSourceHostname()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A hostname can not start or end with a dash', $result->getFirstError());
    }

    public function testValidateWithInvalidTargetHostname()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = '-invalid-target.example.com'; // Invalid target hostname
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A hostname can not start or end with a dash', $result->getFirstError());
    }

    public function testValidateWithEmptyCname()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'example.com'; // Same as zone = empty CNAME
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $rid = 0;
        $zone = 'example.com';

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $rid, $zone);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Empty CNAME records', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 10; // Invalid priority for CNAME record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        // Mock DB response for validateCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidatePriority()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validatePriority');
        $method->setAccessible(true);

        // With empty priority
        $result = $method->invoke($this->validator, '');
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // With zero priority
        $result = $method->invoke($this->validator, 0);
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // With invalid priority
        $result = $method->invoke($this->validator, 10);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for priority field', $result->getFirstError());
    }

    public function testValidateCnameUnique()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameUnique');
        $method->setAccessible(true);

        // Mock DB for unique CNAME
        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $method->invoke($this->validator, 'unique.example.com', 0);
        $this->assertTrue($result->isValid());

        // Mock DB for non-unique CNAME
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->dbMock->method('queryOne')
            ->willReturn(['id' => 1]);
        $this->validator = new CNAMERecordValidator($this->configMock, $this->dbMock);

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameUnique');
        $method->setAccessible(true);

        $result = $method->invoke($this->validator, 'conflict.example.com', 0);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already exists a record', $result->getFirstError());
    }

    public function testValidateCnameName()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameName');
        $method->setAccessible(true);

        // Mock DB for valid CNAME name
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->dbMock->method('queryOne')
            ->willReturn(false);
        $this->validator = new CNAMERecordValidator($this->configMock, $this->dbMock);

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameName');
        $method->setAccessible(true);

        $result = $method->invoke($this->validator, 'valid.example.com');
        $this->assertTrue($result->isValid());

        // Mock DB for invalid CNAME name
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->dbMock->method('queryOne')
            ->willReturn(['id' => 1]);
        $this->validator = new CNAMERecordValidator($this->configMock, $this->dbMock);

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameName');
        $method->setAccessible(true);

        $result = $method->invoke($this->validator, 'invalid.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Did you assign an MX or NS record', $result->getFirstError());
    }

    public function testValidateNotEmptyCnameRR()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateNotEmptyCnameRR');
        $method->setAccessible(true);

        // Valid case (name different from zone)
        $result = $method->invoke($this->validator, 'alias.example.com', 'example.com');
        $this->assertTrue($result->isValid());

        // Invalid case (empty CNAME - name equals zone)
        $result = $method->invoke($this->validator, 'example.com', 'example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Empty CNAME records', $result->getFirstError());
    }
}
