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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The CNAME checks look at the zone the record is written to: another zone's
 * MX, NS or CNAME record must not block or reveal anything about this one.
 */
class CnameZoneScopeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content) VALUES
            (1, 2, 'mail.other.org', 'MX', 'www.victim.example'),
            (2, 2, 'www.other.org', 'A', '192.0.2.1'),
            (3, 2, 'alias.other.org', 'CNAME', 'other.org'),
            (4, 1, 'mx.victim.example', 'MX', 'www.victim.example'),
            (5, 1, 'www.victim.example', 'A', '192.0.2.2'),
            (6, 1, 'alias.victim.example', 'CNAME', 'victim.example')");
    }

    private function config(): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => $default);

        return $config;
    }

    private function validator(?DnsBackendProvider $backend = null): CNAMERecordValidator
    {
        return new CNAMERecordValidator($this->config(), $this->db, $backend);
    }

    private function check(CNAMERecordValidator $validator, string $method, mixed ...$args): bool
    {
        return (new \ReflectionMethod(CNAMERecordValidator::class, $method))->invoke($validator, ...$args)->isValid();
    }

    public function testAnotherZonesMxDoesNotBlockACnameOnTheName(): void
    {
        $validator = $this->validator();

        $this->assertTrue($this->check($validator, 'validateCnameName', 'www.victim.example', 3));
        $this->assertFalse($this->check($validator, 'validateCnameName', 'www.victim.example', 1));
        $this->assertFalse($this->check($validator, 'validateCnameName', 'www.victim.example'));
    }

    public function testAnotherZonesRecordDoesNotBlockACnameOnTheName(): void
    {
        $validator = $this->validator();

        $this->assertTrue($this->check($validator, 'validateCnameUnique', 'www.other.org', -1, 1));
        $this->assertFalse($this->check($validator, 'validateCnameUnique', 'www.other.org', -1, 2));
        $this->assertFalse($this->check($validator, 'validateCnameUnique', 'www.other.org', -1));
    }

    public function testAnotherZonesCnameDoesNotBlockARecordOnTheName(): void
    {
        $validator = $this->validator();

        $this->assertTrue($validator->validateCnameExistence('alias.other.org', -1, 1)->isValid());
        $this->assertFalse($validator->validateCnameExistence('alias.other.org', -1, 2)->isValid());
        $this->assertFalse($validator->validateCnameExistence('alias.other.org', -1)->isValid());
    }

    public function testAnotherZonesCnameDoesNotMakeAMxTargetInvalid(): void
    {
        $validator = new DnsCommonValidator($this->db, $this->config());

        $this->assertTrue($validator->validateNonAliasTarget('alias.other.org', 1)->isValid());
        $this->assertFalse($validator->validateNonAliasTarget('alias.other.org', 2)->isValid());
        $this->assertFalse($validator->validateNonAliasTarget('alias.other.org')->isValid());
    }

    public function testApiBackendChecksIgnoreOtherZones(): void
    {
        $records = [
            ['id' => 'a', 'domain_id' => 2, 'name' => 'www.victim.example', 'type' => 'A', 'content' => '192.0.2.1'],
            ['id' => 'b', 'domain_id' => 2, 'name' => 'x.victim.example', 'type' => 'MX', 'content' => 'www.victim.example'],
            ['id' => 'c', 'domain_id' => 2, 'name' => 'alias.victim.example', 'type' => 'CNAME', 'content' => 'victim.example'],
        ];
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')->willReturn(['zones' => [], 'records' => $records]);
        $validator = $this->validator($backend);

        $this->assertTrue($this->check($validator, 'validateCnameUnique', 'www.victim.example', -1, 1));
        $this->assertFalse($this->check($validator, 'validateCnameUnique', 'www.victim.example', -1, 2));
        $this->assertTrue($this->check($validator, 'validateCnameName', 'www.victim.example', 1));
        $this->assertFalse($this->check($validator, 'validateCnameName', 'www.victim.example', 2));
        $this->assertTrue($validator->validateCnameExistence('alias.victim.example', -1, 1)->isValid());
        $this->assertFalse($validator->validateCnameExistence('alias.victim.example', -1, 2)->isValid());
    }
}
