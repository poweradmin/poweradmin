<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

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

        // Track which test case we're in for different return values
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $callCount = 0;
        $stmtMock->method('fetchColumn')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // First call: valid case (no conflicts)
                // Second call: invalid case (conflict found)
                return $callCount === 1 ? false : 1;
            });

        $dbMock->method('prepare')
            ->willReturn($stmtMock);

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

        // Setup mock prepared statement responses
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $callCount = 0;
        $stmtMock->method('fetchColumn')
        ->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            // First two calls: valid cases (no existing CNAME)
            // Third call: invalid case (existing CNAME found)
            return $callCount <= 2 ? false : 1;
        });

        $dbMock->method('prepare')
        ->willReturn($stmtMock);

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

        // Setup mock prepared statement responses
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')
        ->willReturn(false); // No conflicting records found for valid cases

        $dbMock->method('prepare')
        ->willReturn($stmtMock);

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
        $stmtMock1 = $this->createMock(\PDOStatement::class);
        $stmtMock1->method('execute')->willReturn(true);
        $stmtMock1->method('fetchColumn')->willReturn(false); // No CNAME found
        $dbMock1->method('prepare')->willReturn($stmtMock1);

        $validator1 = new DnsCommonValidator($dbMock1, $configMock);
        $result1 = $validator1->validateNonAliasTarget('valid.example.com');
        $this->assertTrue($result1->isValid());
        $this->assertTrue($result1->getData());

        // Test invalid case - target is a CNAME
        $dbMock2 = $this->createMock(PDOLayer::class);
        $stmtMock2 = $this->createMock(\PDOStatement::class);
        $stmtMock2->method('execute')->willReturn(true);
        $stmtMock2->method('fetchColumn')->willReturn(1); // CNAME found
        $dbMock2->method('prepare')->willReturn($stmtMock2);

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
