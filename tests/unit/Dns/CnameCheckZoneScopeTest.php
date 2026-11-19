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
 * The CNAME checks only look at the zone the record is written to: another zone's
 * MX, NS or CNAME rows neither block the write nor reveal themselves through it.
 */
class CnameCheckZoneScopeTest extends TestCase
{
    private const ZONE_A = 1;
    private const ZONE_B = 2;

    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, type TEXT, content TEXT)');
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturn('');
    }

    private function addRecord(int $zid, string $name, string $type, string $content): int
    {
        $this->db->prepare('INSERT INTO records (domain_id, name, type, content) VALUES (?, ?, ?, ?)')->execute([$zid, $name, $type, $content]);
        return (int)$this->db->lastInsertId();
    }

    private function cname(): CNAMERecordValidator
    {
        return new CNAMERecordValidator($this->config, $this->db);
    }

    private function call(string $method, mixed ...$args): bool
    {
        return (new \ReflectionMethod(CNAMERecordValidator::class, $method))->invoke($this->cname(), ...$args)->isValid();
    }

    public function testAnotherZonesMxPointingAtTheNameDoesNotBlockTheCname(): void
    {
        $this->addRecord(self::ZONE_B, 'example.net', 'MX', 'www.victim.example');

        $this->assertTrue($this->call('validateCnameName', 'www.victim.example', self::ZONE_A));
        $this->assertFalse($this->call('validateCnameName', 'www.victim.example', self::ZONE_B));
        $this->assertFalse($this->call('validateCnameName', 'www.victim.example'), 'No zone keeps the old lookup');
    }

    public function testSameZoneNsPointingAtTheNameStillBlocksTheCname(): void
    {
        $this->addRecord(self::ZONE_A, 'sub.victim.example', 'NS', 'www.victim.example');

        $this->assertFalse($this->call('validateCnameName', 'www.victim.example', self::ZONE_A));
    }

    public function testAnotherZonesRecordWithTheNameDoesNotBlockTheCname(): void
    {
        $this->addRecord(self::ZONE_B, 'www.victim.example', 'A', '192.0.2.1');

        $this->assertTrue($this->call('validateCnameUnique', 'www.victim.example', -1, self::ZONE_A));
        $this->assertFalse($this->call('validateCnameUnique', 'www.victim.example', -1, self::ZONE_B));
    }

    public function testSameZoneRecordWithTheNameStillBlocksTheCname(): void
    {
        $this->addRecord(self::ZONE_A, 'www.victim.example', 'A', '192.0.2.1');

        $this->assertFalse($this->call('validateCnameUnique', 'www.victim.example', -1, self::ZONE_A));
    }

    public function testAnotherZonesCnameDoesNotBlockARecordWithTheSameName(): void
    {
        $this->addRecord(self::ZONE_B, 'www.victim.example', 'CNAME', 'target.example.');

        $validator = $this->cname();
        $this->assertTrue($validator->validateCnameExistence('www.victim.example', -1, self::ZONE_A)->isValid());
        $this->assertFalse($validator->validateCnameExistence('www.victim.example', -1, self::ZONE_B)->isValid());
        $this->assertFalse($validator->validateCnameExistence('www.victim.example', -1)->isValid(), 'No zone keeps the old lookup');
    }

    public function testSameZoneCnameStillBlocksAnotherTypeWithTheSameName(): void
    {
        $this->addRecord(self::ZONE_A, 'www.victim.example', 'CNAME', 'target.example.');

        $this->assertFalse($this->cname()->validateCnameExistence('www.victim.example', -1, self::ZONE_A)->isValid());
    }

    public function testNonAliasTargetOnlyLooksAtTheZoneOfTheRecord(): void
    {
        $this->addRecord(self::ZONE_B, 'mail.victim.example', 'CNAME', 'other.example.');
        $validator = new DnsCommonValidator($this->db, $this->config);

        $this->assertTrue($validator->validateNonAliasTarget('mail.victim.example', self::ZONE_A)->isValid());
        $this->assertFalse($validator->validateNonAliasTarget('mail.victim.example', self::ZONE_B)->isValid());
        $this->assertFalse($validator->validateNonAliasTarget('mail.victim.example')->isValid());
    }

    public function testApiBackendLookupsKeepOnlyTheRecordsOfTheZone(): void
    {
        $rows = [
            ['id' => 'x', 'domain_id' => self::ZONE_B, 'name' => 'example.net', 'type' => 'MX', 'content' => 'www.victim.example'],
            ['id' => 'y', 'domain_id' => self::ZONE_B, 'name' => 'www.victim.example', 'type' => 'A', 'content' => '192.0.2.1'],
            ['id' => 'z', 'domain_id' => self::ZONE_B, 'name' => 'www.victim.example', 'type' => 'CNAME', 'content' => 't.example'],
        ];
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')->willReturn(['zones' => [], 'records' => $rows]);
        $validator = new CNAMERecordValidator($this->config, $this->db, $backend);
        $unique = new \ReflectionMethod(CNAMERecordValidator::class, 'validateCnameUnique');
        $name = new \ReflectionMethod(CNAMERecordValidator::class, 'validateCnameName');

        $this->assertTrue($name->invoke($validator, 'www.victim.example', self::ZONE_A)->isValid());
        $this->assertFalse($name->invoke($validator, 'www.victim.example', self::ZONE_B)->isValid());
        $this->assertTrue($unique->invoke($validator, 'www.victim.example', -1, self::ZONE_A)->isValid());
        $this->assertFalse($unique->invoke($validator, 'www.victim.example', -1, self::ZONE_B)->isValid());
        $this->assertTrue($validator->validateCnameExistence('www.victim.example', -1, self::ZONE_A)->isValid());
        $this->assertFalse($validator->validateCnameExistence('www.victim.example', -1, self::ZONE_B)->isValid());
    }
}
