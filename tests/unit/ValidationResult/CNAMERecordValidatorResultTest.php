<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for CNAMERecordValidator using ValidationResult pattern
 */
class CNAMERecordValidatorResultTest extends TestCase
{
    private CNAMERecordValidator $validator;
    private ConfigurationManager $configMock;
    private PDOLayer $dbMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturnCallback(function ($section, $key = null) {
                if ($section === 'database' && $key === 'pdns_name') {
                    return '';
                }
                return 'example.com';
            });

        $this->dbMock = $this->createMock(PDOLayer::class);

        $this->validator = new CNAMERecordValidator($this->configMock, $this->dbMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock database query for CNAME uniqueness check
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithNonUniqueCname()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock database query to indicate a conflict exists
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(1); // Return record ID to indicate conflict

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('not a valid CNAME', $result->getFirstError());
    }

    public function testValidateWithMxOrNsConflict()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock database calls with conditional return values
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturnCallback(function ($query) {
                // First call: CNAME uniqueness check
                if (strpos($query, "AND TYPE != 'CNAME'") !== false) {
                    return false; // No conflict with other record types
                }

                // Second call: MX/NS check
                if (strpos($query, "(type = 'MX' OR type = 'NS')") !== false) {
                    return 1; // Has MX or NS conflicting record
                }

                return false;
            });

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('MX or NS', $result->getFirstError());
    }

    public function testValidateWithEmptyCnameRRConflict()
    {
        $content = 'target.example.com';
        $name = 'example.com'; // Same as zone name
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $zone = 'example.com';

        // Mock successful database queries for initial checks
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, 0, $zone);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Empty CNAME', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTargetHostname()
    {
        $content = '-invalid-target.example.com'; // Invalid target hostname
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 10; // Invalid priority for CNAME record
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'target.example.com';
        $name = 'cname.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        // Mock successful database queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                return "'$value'";
            });

        $this->dbMock->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
