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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Tests for common DNS validation functions
 */
class DnsCommonValidatorTest extends TestCase
{
    private DnsCommonValidator $validator;
    private MockObject&PDOCommon $dbMock;
    private MockObject&ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOCommon::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new DnsCommonValidator($this->dbMock, $this->configMock);
    }

    /**
     * Test priority validation with ValidationResult pattern
     */
    public function testValidatePriorityWithMxRecords()
    {
        // Test default values for MX records
        $result1 = $this->validator->validatePriority(null, "MX");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(10, $result1->getData());

        $result2 = $this->validator->validatePriority("", "MX");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(10, $result2->getData());

        // Test valid MX priorities
        $result3 = $this->validator->validatePriority(0, "MX");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(0, $result3->getData());

        $result4 = $this->validator->validatePriority(10, "MX");
        $this->assertTrue($result4->isValid());
        $this->assertEquals(10, $result4->getData());

        $result5 = $this->validator->validatePriority(65535, "MX");
        $this->assertTrue($result5->isValid());
        $this->assertEquals(65535, $result5->getData());

        // Test invalid priorities for MX records
        $result6 = $this->validator->validatePriority(-1, "MX");
        $this->assertFalse($result6->isValid());
        $this->assertNotEmpty($result6->getErrors());

        $result7 = $this->validator->validatePriority(65536, "MX");
        $this->assertFalse($result7->isValid());

        $result8 = $this->validator->validatePriority("invalid", "MX");
        $this->assertFalse($result8->isValid());
    }

    public function testValidatePriorityWithSrvRecords()
    {
        // Test default values and valid priorities for SRV records
        $result1 = $this->validator->validatePriority(null, "SRV");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(10, $result1->getData());

        $result2 = $this->validator->validatePriority(0, "SRV");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(0, $result2->getData());

        $result3 = $this->validator->validatePriority(65535, "SRV");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(65535, $result3->getData());
    }

    public function testValidatePriorityWithNonPriorityRecords()
    {
        // Test non-MX/SRV records - should always return 0 regardless of input
        $result1 = $this->validator->validatePriority(null, "A");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(0, $result1->getData());

        $result2 = $this->validator->validatePriority("", "A");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(0, $result2->getData());

        $result3 = $this->validator->validatePriority(10, "A");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(0, $result3->getData());

        $result4 = $this->validator->validatePriority(100, "AAAA");
        $this->assertTrue($result4->isValid());
        $this->assertEquals(0, $result4->getData());

        $result5 = $this->validator->validatePriority("invalid", "TXT");
        $this->assertTrue($result5->isValid());
        $this->assertEquals(0, $result5->getData());
    }

    /**
     * Test non-alias target validation with ValidationResult pattern
     */
    public function testValidateNonAliasTargetWithNoCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for prepared statement
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn(false); // No CNAME found

        $this->dbMock->expects($this->once())
            ->method('prepare')
            ->willReturn($stmtMock);

        $result = $this->validator->validateNonAliasTarget("example.com");
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->getData());
    }

    public function testValidateNonAliasTargetWithCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for prepared statement
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn(1); // CNAME found

        $this->dbMock->expects($this->once())
            ->method('prepare')
            ->willReturn($stmtMock);

        $result = $this->validator->validateNonAliasTarget("has.cname.example.com");
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('You can not point a NS or MX record to a CNAME record', $result->getFirstError());
    }
}
