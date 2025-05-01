<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;

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
        // Mock DB response for isValidCnameUnique
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

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
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

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSourceHostname()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTargetHostname()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = '-invalid-target.example.com'; // Invalid target hostname
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyCname()
    {
        // Mock DB response for isValidCnameUnique
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

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPriority()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 10; // Invalid priority for CNAME record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyPriority()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        // Mock DB response for isValidCnameUnique
        $this->dbMock->method('queryOne')
            ->willReturn(false); // No conflicts found

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testIsValidCnameUnique()
    {
        // Mock DB response for a unique CNAME
        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $this->assertTrue($this->validator->isValidCnameUnique('unique.example.com', 0));
    }

    public function testIsValidCnameUniqueWithConflict()
    {
        // Mock DB response for a conflicting record
        $this->dbMock->method('queryOne')
            ->willReturn(['id' => 1]);

        $this->assertFalse($this->validator->isValidCnameUnique('conflict.example.com', 0));
    }

    public function testIsValidCnameName()
    {
        // Mock DB response for a valid CNAME name
        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $this->assertTrue($this->validator->isValidCnameName('valid.example.com'));
    }

    public function testIsValidCnameNameWithConflict()
    {
        // Mock DB response for a name with MX or NS records pointing to it
        $this->dbMock->method('queryOne')
            ->willReturn(['id' => 1]);

        $this->assertFalse($this->validator->isValidCnameName('invalid.example.com'));
    }

    public function testIsNotEmptyCnameRR()
    {
        $this->assertTrue($this->validator->isNotEmptyCnameRR('alias.example.com', 'example.com'));
    }

    public function testIsNotEmptyCnameRRWithEmptyCname()
    {
        $this->assertFalse($this->validator->isNotEmptyCnameRR('example.com', 'example.com'));
    }
}
