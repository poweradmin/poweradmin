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

use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use PHPUnit\Framework\TestCase;

class SOARecordValidatorTest extends TestCase
{
    private SOARecordValidator $validator;
    private $dbMock;
    private $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Configure the database mock for Validator class queries
        $this->dbMock->method('queryOne')
            ->willReturn(null); // For simplicity, assume validation passes

        // Configure the quote method to handle SQL queries
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'text' || $type === 'integer') {
                    return "'$value'";
                }
                return "'$value'";
            });

        $this->validator = new SOARecordValidator($this->configMock, $this->dbMock);
    }

    public function testValidateWithValidData()
    {
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $name = "example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $this->validator->setSOAParams($dns_hostmaster, $zone);
        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('prio', $data);
        $this->assertArrayHasKey('ttl', $data);

        $this->assertEquals(0, $data['prio']);

        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidZoneName()
    {
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $name = "www.example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $dns_hostmaster, $zone);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidContent()
    {
        $content = "ns1.example.com hostmaster.example.com"; // Missing required fields
        $name = "example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $dns_hostmaster, $zone);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostmaster()
    {
        $content = "ns1.example.com invalid-email 2023122801 7200 1800 1209600 86400";
        $name = "example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $dns_hostmaster, $zone);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithNonNumericSerial()
    {
        $content = "ns1.example.com hostmaster.example.com abc 7200 1800 1209600 86400";
        $name = "example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $dns_hostmaster, $zone);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $name = "example.com";
        $prio = 0;
        $ttl = -1;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.com";

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $dns_hostmaster, $zone);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithArpaDomain()
    {
        $content = "example.arpa hostmaster.example.com 2023122505 7200 1209600 3600 86400";
        $name = "example.arpa";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $dns_hostmaster = "hostmaster@example.com";
        $zone = "example.arpa";

        $this->validator->setSOAParams($dns_hostmaster, $zone);
        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithoutSOAParams()
    {
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $name = "example.com";
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }
}
