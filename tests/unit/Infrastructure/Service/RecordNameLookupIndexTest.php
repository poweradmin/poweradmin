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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * Record validation looks names up once per record, so a lookup that cannot use the
 * name index scans the whole records table (or zone) for every line of a bulk add.
 * The tables carry the indexes of the PowerDNS SQLite schema, and the query plan of
 * the statement each lookup prepared must search on the name.
 */
#[CoversClass(SqlDnsBackendProvider::class)]
#[CoversClass(SqlRecordRepository::class)]
final class RecordNameLookupIndexTest extends TestCase
{
    /** @var PDO&object{prepared: list<string>} */
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new class ('sqlite::memory:') extends PDO {
            /** @var list<string> Every statement prepared, in order */
            public array $prepared = [];

            public function __construct(string $dsn)
            {
                parent::__construct($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->prepared[] = $query;
                return parent::prepare($query, $options);
            }
        };
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("CREATE INDEX records_lookup_idx ON records(name, type)");
        $this->db->exec("CREATE INDEX records_lookup_id_idx ON records(domain_id, name, type)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'example.com', 'MASTER'), (2, 'other.org', 'NATIVE')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES
            (10, 1, 'www.example.com', 'A', '192.0.2.1', 3600, 0),
            (11, 1, 'mail.example.com', 'MX', 'www.example.com', 3600, 10),
            (12, 2, 'www.example.com', 'CNAME', 'other.org', 3600, 0)");
    }

    private function config(): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $section, string $key, $default = null) => $section === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        return $config;
    }

    private function provider(): SqlDnsBackendProvider
    {
        return new SqlDnsBackendProvider($this->db, $this->config(), new NullLogger());
    }

    /**
     * The plan SQLite chooses for the last statement a lookup prepared.
     */
    private function lastPlan(): string
    {
        $sql = end($this->db->prepared);
        $this->assertIsString($sql);
        $plan = $this->db->query('EXPLAIN QUERY PLAN ' . $sql);
        $this->assertInstanceOf(PDOStatement::class, $plan);

        return implode(' | ', array_column($plan->fetchAll(), 'detail'));
    }

    public function testTheCrossZoneLookupSearchesTheNameIndex(): void
    {
        $ids = array_map('intval', array_column($this->provider()->findRecordsByName('WWW.Example.COM.'), 'id'));

        $this->assertSame([10, 12], $ids);
        $this->assertStringContainsString('name=?', $this->lastPlan());
    }

    public function testTheCrossZoneLookupByTypeSearchesTheNameIndex(): void
    {
        $records = $this->provider()->findRecordsByName('WWW.example.com', 'CNAME');

        $this->assertSame([12], array_map('intval', array_column($records, 'id')));
        $this->assertStringContainsString('name=?', $this->lastPlan());
    }

    public function testTheZoneLookupSearchesOnTheNameAsWellAsTheZone(): void
    {
        $records = $this->provider()->getRecordsByName(1, 'WWW.EXAMPLE.COM.');

        $this->assertSame([10], array_map('intval', array_column($records, 'id')));
        $this->assertStringContainsString('name=?', $this->lastPlan());
    }

    public function testTheRepositoryZoneLookupSearchesOnTheNameAsWellAsTheZone(): void
    {
        $records = (new SqlRecordRepository($this->db, $this->config()))->getRecordsByName(1, 'Mail.Example.Com', 'MX');

        $this->assertSame([11], array_map('intval', array_column($records, 'id')));
        $this->assertStringContainsString('name=?', $this->lastPlan());
    }

    public function testANameWrittenInMixedCaseIsStoredLowercaseSoTheLookupsFindIt(): void
    {
        // Zone templates hand the provider names as typed, e.g. WWW.[ZONE]
        $provider = $this->provider();
        $id = $provider->addRecordGetId(1, 'Ftp.Example.com', 'A', '192.0.2.9', 3600, 0);

        $stored = $this->db->query("SELECT name FROM records WHERE id = $id");
        $this->assertInstanceOf(PDOStatement::class, $stored);
        $this->assertSame('ftp.example.com', $stored->fetchColumn());
        $this->assertSame([$id], array_map('intval', array_column($provider->findRecordsByName('ftp.example.com'), 'id')));
        $this->assertTrue($provider->recordExists(1, 'FTP.example.com', 'A', '192.0.2.9'));
    }

    public function testTheRepositoryExistenceCheckIgnoresTheCaseOfTheInput(): void
    {
        // A zone file import hands over names as written, e.g. WWW.example.com
        $repository = new SqlRecordRepository($this->db, $this->config());

        $this->assertTrue($repository->recordExists(1, 'WWW.Example.com', 'A', '192.0.2.1'));
        $this->assertFalse($repository->recordExists(1, 'WWW.Example.com', 'A', '192.0.2.99'));
    }
}
