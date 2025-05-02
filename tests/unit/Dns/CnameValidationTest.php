<?php

namespace unit\Dns;

use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use TestHelpers\BaseDnsTest;

/**
 * Tests for CNAME record validation
 */
class CnameValidationTest extends BaseDnsTest
{
    public function testIsValidRrCnameName()
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

        // Valid CNAME name (no MX/NS records exist that point to it)
        $name = 'valid.cname.example.com';
        $result = $validator->isValidCnameName($name);
        $this->assertTrue($result);

        // Invalid CNAME name (MX/NS record points to it)
        $name = 'invalid.cname.target';
        $result = $validator->isValidCnameName($name);
        $this->assertFalse($result);
    }

    public function testIsValidCnameExistence()
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

        // Valid case - no existing CNAME record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $validator->isValidCnameExistence($name, $rid);
        $this->assertTrue($result);

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $validator->isValidCnameExistence($name, $rid);
        $this->assertTrue($result);

        // Invalid case - CNAME record already exists with this name
        $name = 'existing.cname.example.com';
        $rid = 0;
        $result = $validator->isValidCnameExistence($name, $rid);
        $this->assertFalse($result);
    }

    public function testIsValidCnameUnique()
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

        // Valid case - no existing record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $validator->isValidCnameUnique($name, $rid);
        $this->assertTrue($result);

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $validator->isValidCnameUnique($name, $rid);
        $this->assertTrue($result);
    }

    public function testIsValidNonAliasTarget()
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
        $this->assertTrue($validator1->isValidNonAliasTarget('valid.example.com'));

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
        $this->assertFalse($validator2->isValidNonAliasTarget('alias.example.com'));
    }

    public function testIsNotEmptyCnameRR()
    {
        // Create CNAMERecordValidator instance
        $configMock = $this->createMock(ConfigurationManager::class);
        $dbMock = $this->createMock(PDOLayer::class);

        $validator = new CNAMERecordValidator($configMock, $dbMock);

        // Valid non-empty CNAME
        $this->assertTrue($validator->isNotEmptyCnameRR('subdomain.example.com', 'example.com'));
        $this->assertTrue($validator->isNotEmptyCnameRR('www.example.com', 'example.com'));

        // Invalid empty CNAME (name equals zone)
        $this->assertFalse($validator->isNotEmptyCnameRR('example.com', 'example.com'));
    }
}
