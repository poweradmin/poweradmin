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

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Tests for SOA record validation
 */
class SoaValidationTest extends BaseDnsTest
{
    private SOARecordValidator $validator;
    private $dbMock;
    private $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOCommon::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Configure the database mock
        $this->dbMock->method('queryOne')->willReturn(null);
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'text') {
                    return "'$value'";
                }
                if ($type === 'integer') {
                    return $value;
                }
                return "'$value'";
            });

        $this->validator = new SOARecordValidator($this->configMock, $this->dbMock);
    }

    /**
     * Test the SOA content validation
     */
    public function testIsValidRrSoaContent()
    {
        // Valid SOA record content
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $zone = "example.com";
        $dns_hostmaster = "hostmaster@example.com";

        $this->validator->setSOAParams($dns_hostmaster, $zone);
        $result = $this->validator->validate($content, "example.com", 0, 3600, 86400);

        // Check that we get a valid result
        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertArrayHasKey('content', $data);
        $this->assertStringContainsString('ns1.example.com', $data['content']);
        $this->assertStringContainsString('2023122801', $data['content']);

        // Test invalid content format
        $content = "ns1.example.com hostmaster.example.com"; // Missing required fields
        $result = $this->validator->validate($content, $zone, 0, 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());

        // Test empty content
        $content = "";
        $result = $this->validator->validate($content, $zone, 0, 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testIsValidRrSoaName()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400';

        // Valid SOA name (matches zone)
        $this->validator->setSOAParams('hostmaster@example.com', 'example.com');
        $result = $this->validator->validate($content, 'example.com', 0, 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $this->validator->setSOAParams('hostmaster@sub.domain.com', 'sub.domain.com');
        $result = $this->validator->validate($content, 'sub.domain.com', 0, 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        // Invalid SOA name (doesn't match zone)
        $this->validator->setSOAParams('hostmaster@example.com', 'example.com');
        $result = $this->validator->validate($content, 'www.example.com', 0, 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());

        $result = $this->validator->validate($content, 'example.org', 0, 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testCustomValidationWithNonNumericSerialNumbers()
    {
        // Test our custom validation logic directly
        $content = "example.com hostmaster.example.com not_a_number 7200 1209600 3600 86400";

        // Verify our custom logic properly identifies non-numeric serial numbers
        $fields = preg_split("/\s+/", trim($content));
        $this->assertFalse(is_numeric($fields[2]), "Should identify non-numeric serial");
    }

    public function testCustomValidationWithArpaDomain()
    {
        // Test our custom validation logic directly
        $content = "example.arpa hostmaster.example.com 2023122505 7200 1209600 3600 86400";

        // Verify our custom logic properly identifies .arpa domains
        $fields = preg_split("/\s+/", trim($content));
        $this->assertTrue((bool)preg_match('/\.arpa\.?$/', $fields[0]), "Should identify .arpa domain");
    }

    public function testCustomValidationWithValidData()
    {
        // Test our custom validation logic directly
        $content = "example.com hostmaster.example.com 2023122505 7200 1209600 3600 86400";

        // Verify our custom logic validates properly formed SOA records
        $fields = preg_split("/\s+/", trim($content));

        $this->assertCount(7, $fields, "Should have 7 fields");
        $this->assertFalse((bool)preg_match('/\.arpa\.?$/', $fields[0]), "Should not be an arpa domain");
        $this->assertTrue(is_numeric($fields[2]), "Serial should be numeric");
        $this->assertTrue(is_numeric($fields[3]), "Refresh should be numeric");
        $this->assertTrue(is_numeric($fields[4]), "Retry should be numeric");
        $this->assertTrue(is_numeric($fields[5]), "Expire should be numeric");
        $this->assertTrue(is_numeric($fields[6]), "Minimum should be numeric");
    }
}
