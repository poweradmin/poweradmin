<?php

namespace unit\Dns;

use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
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
        // Valid case - target is not a CNAME
        $target = 'valid.example.com';
        $result = $this->dnsInstance->is_valid_non_alias_target($target);
        $this->assertTrue($result);

        // Invalid case - target is a CNAME
        $target = 'alias.example.com';
        $result = $this->dnsInstance->is_valid_non_alias_target($target);
        $this->assertFalse($result);
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
