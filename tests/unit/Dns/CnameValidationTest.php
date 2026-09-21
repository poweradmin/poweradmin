<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Dns;

use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use TestHelpers\BaseDnsTest;
use ReflectionClass;

/**
 * Tests for CNAME record validation
 *
 * Lookups run against a SqlDnsBackendProvider over in-memory sqlite seeded per test.
 */
class CnameValidationTest extends BaseDnsTest
{
    public function testValidateCnameName()
    {
        // Create CNAMERecordValidator instance
        $hostnameValidator = new HostnameValidator(new HostnamePolicy());

        // Only the second name is the target of an NS record
        $validator = new CNAMERecordValidator($hostnameValidator, $this->sqliteBackendProvider([
            [10, 1, 'example.com', 'NS', 'invalid.cname.target'],
        ]));
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
        $hostnameValidator = new HostnameValidator(new HostnamePolicy());

        // Only the existing.cname name already carries a CNAME
        $validator = new CNAMERecordValidator($hostnameValidator, $this->sqliteBackendProvider([
            [10, 1, 'existing.cname.example.com', 'CNAME', 'target.example.com'],
        ]));
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
        $hostnameValidator = new HostnameValidator(new HostnamePolicy());

        // No records share the name, so both a new record and an edit pass
        $validator = new CNAMERecordValidator($hostnameValidator, $this->sqliteBackendProvider([
            [10, 1, 'other.example.com', 'A', '192.0.2.1'],
        ]));
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
        // Test valid case - target is not a CNAME
        $validator1 = new DnsCommonValidator($this->sqliteBackendProvider([
            [10, 1, 'valid.example.com', 'A', '192.0.2.1'],
        ]));
        $result1 = $validator1->validateNonAliasTarget('valid.example.com');
        $this->assertTrue($result1->isValid());
        $this->assertTrue($result1->getData());

        // Test invalid case - target is a CNAME
        $validator2 = new DnsCommonValidator($this->sqliteBackendProvider([
            [11, 1, 'alias.example.com', 'CNAME', 'valid.example.com'],
        ]));
        $result2 = $validator2->validateNonAliasTarget('alias.example.com');
        $this->assertFalse($result2->isValid());
        $this->assertNotEmpty($result2->getErrors());
    }

    public function testValidateNotEmptyCnameRR()
    {
        // Create CNAMERecordValidator instance
        $hostnameValidator = new HostnameValidator(new HostnamePolicy());

        $validator = new CNAMERecordValidator($hostnameValidator, $this->sqliteBackendProvider());
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
