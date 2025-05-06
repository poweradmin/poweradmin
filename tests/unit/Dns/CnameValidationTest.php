<?php

namespace unit\Dns;

use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use TestHelpers\BaseDnsTest;
use ReflectionClass;

/**
 * Tests for CNAME record validation
 */
class CnameValidationTest extends BaseDnsTest
{
    public function testValidateCnameName()
    {
        // Create CNAMERecordValidator instance
        $configMock = $this->createMock(ConfigurationManager::class);
        $dbMock = $this->createMock(PDOLayer::class);

        // Setup mock database responses
        $dbMock->expects($this->atLeastOnce())
            ->method('queryOne')
            ->willReturnCallback(function ($query) {
                if (strpos($query, "'invalid.cname.target'") !== false) {
                    return ['id' => 1];  // MX or NS record exists
                }
                return false;  // No conflicting records
            });

        // Setup quote method mock
        $dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'integer') {
                    return (string)$value;
                }
                return "'$value'";
            });

        $validator = new CNAMERecordValidator($configMock, $dbMock);
        $reflection = new ReflectionClass($validator);
        $method = $reflection->getMethod('validateCnameName');
        $method->setAccessible(true);

        // Valid CNAME name (no MX/NS records exist that point to it)
        $name = 'valid.cname.example.com';
        $result = $method->invoke($validator, $name);
        $this->assertTrue($result->isValid());

        // Invalid CNAME name (MX/NS record points to it)
        $name = 'invalid.cname.target';
        $result = $method->invoke($validator, $name);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Did you assign an MX or NS record', $result->getFirstError());
    }

    public function testValidateCnameExistence()
    {
        // Create CNAMERecordValidator instance
        $configMock = $this->createMock(ConfigurationManager::class);
        $dbMock = $this->createMock(PDOLayer::class);

        // Setup mock database responses before creating validator
        $dbMock->expects($this->atLeastOnce())
            ->method('queryOne')
            ->willReturnCallback(function ($query) {
                if (strpos($query, 'existing.cname.example.com') !== false) {
                    return ['id' => 1];  // Record exists
                }
                return false;  // No record found
            });

        // Setup quote method mock
        $dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'integer') {
                    return (string)$value; // Convert to string for integer values
                }
                return "'$value'";
            });

        // Create validator after setting up mocks
        $validator = new CNAMERecordValidator($configMock, $dbMock);
        $reflection = new ReflectionClass($validator);
        $method = $reflection->getMethod('validateCnameExistence');
        $method->setAccessible(true);

        // Valid case - no existing CNAME record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $method->invoke($validator, $name, $rid);
        $this->assertTrue($result->isValid());

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $method->invoke($validator, $name, $rid);
        $this->assertTrue($result->isValid());

        // Invalid case - CNAME record already exists with this name
        $name = 'existing.cname.example.com';
        $rid = 0;
        $result = $method->invoke($validator, $name, $rid);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already exists a CNAME', $result->getFirstError());
    }

    public function testValidateCnameUnique()
    {
        // Create CNAMERecordValidator instance
        $configMock = $this->createMock(ConfigurationManager::class);
        $dbMock = $this->createMock(PDOLayer::class);

        // Setup mock database responses
        $dbMock->expects($this->atLeastOnce())
            ->method('queryOne')
            ->willReturnCallback(function ($query) {
                return false;  // No conflicting records found for valid cases
            });

        // Setup quote method mock
        $dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'integer') {
                    return (string)$value;
                }
                return "'$value'";
            });

        $validator = new CNAMERecordValidator($configMock, $dbMock);
        $reflection = new ReflectionClass($validator);
        $method = $reflection->getMethod('validateCnameUnique');
        $method->setAccessible(true);

        // Valid case - no existing record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $method->invoke($validator, $name, $rid);
        $this->assertTrue($result->isValid());

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $method->invoke($validator, $name, $rid);
        $this->assertTrue($result->isValid());
    }

    public function testValidateNonAliasTarget()
    {
        // Create mocks that will be used for both test cases
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturn('pdns');

        // Test valid case - target is not a CNAME
        $dbMock1 = $this->createMock(PDOLayer::class);
        $dbMock1->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });
        $dbMock1->expects($this->once())
            ->method('queryOne')
            ->willReturn(false);

        $validator1 = new DnsCommonValidator($dbMock1, $configMock);
        $result1 = $validator1->validateNonAliasTarget('valid.example.com');
        $this->assertTrue($result1->isValid());
        $this->assertTrue($result1->getData());

        // Test invalid case - target is a CNAME
        $dbMock2 = $this->createMock(PDOLayer::class);
        $dbMock2->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });
        $dbMock2->expects($this->once())
            ->method('queryOne')
            ->willReturn(['id' => 1]);

        $validator2 = new DnsCommonValidator($dbMock2, $configMock);
        $result2 = $validator2->validateNonAliasTarget('alias.example.com');
        $this->assertFalse($result2->isValid());
        $this->assertNotEmpty($result2->getErrors());
    }

    public function testValidateNotEmptyCnameRR()
    {
        // Create CNAMERecordValidator instance
        $configMock = $this->createMock(ConfigurationManager::class);
        $dbMock = $this->createMock(PDOLayer::class);

        $validator = new CNAMERecordValidator($configMock, $dbMock);
        $reflection = new ReflectionClass($validator);
        $method = $reflection->getMethod('validateNotEmptyCnameRR');
        $method->setAccessible(true);

        // Valid non-empty CNAME
        $result1 = $method->invoke($validator, 'subdomain.example.com', 'example.com');
        $this->assertTrue($result1->isValid());

        $result2 = $method->invoke($validator, 'www.example.com', 'example.com');
        $this->assertTrue($result2->isValid());

        // Invalid empty CNAME (name equals zone)
        $result3 = $method->invoke($validator, 'example.com', 'example.com');
        $this->assertFalse($result3->isValid());
        $this->assertStringContainsString('Empty CNAME records', $result3->getFirstError());
    }
}
