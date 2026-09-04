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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * A rename would update domains.name while leaving every records.name under the old
 * name, silently orphaning the whole zone. SQL mode must refuse it just as API backend
 * mode already does, while still accepting a name equal to the current one so clients
 * that PUT the whole zone object back keep working.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryUpdateZoneRenameTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("INSERT INTO domains (id, name, type, master, account) VALUES (1, 'example.com', 'NATIVE', '', '')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content)
            VALUES (1, 1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600'),
                   (2, 1, 'www.example.com', 'A', '192.0.2.1')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'database' && $key === 'type') {
                    return 'sqlite';
                }
                return $default;
            });

        $this->repository = new DbZoneRepository($this->db, $config);
    }

    private function domainName(): string
    {
        return (string) $this->db->query("SELECT name FROM domains WHERE id = 1")->fetchColumn();
    }

    private function recordNames(): array
    {
        return $this->db->query("SELECT name FROM records ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testRenameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Zone renaming is not supported');

        $this->repository->updateZone(1, ['name' => 'renamed.example.net']);
    }

    public function testRejectedRenameLeavesDomainAndRecordsUntouched(): void
    {
        try {
            $this->repository->updateZone(1, ['name' => 'renamed.example.net', 'type' => 'MASTER']);
            $this->fail('Expected the rename to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('example.com', $this->domainName());
        $this->assertSame(['example.com', 'www.example.com'], $this->recordNames());
        // The rejection must abort the whole update, not apply the other fields.
        $this->assertSame('NATIVE', (string) $this->db->query("SELECT type FROM domains WHERE id = 1")->fetchColumn());
    }

    public function testUnchangedNameIsAcceptedAsNoOp(): void
    {
        $this->assertTrue($this->repository->updateZone(1, ['name' => 'example.com']));
        $this->assertSame('example.com', $this->domainName());
    }

    public function testUnchangedNameAlongsideOtherFieldsStillUpdatesThem(): void
    {
        $this->assertTrue($this->repository->updateZone(1, ['name' => 'example.com', 'type' => 'MASTER']));

        $this->assertSame('MASTER', (string) $this->db->query("SELECT type FROM domains WHERE id = 1")->fetchColumn());
        $this->assertSame('example.com', $this->domainName());
        $this->assertSame(['example.com', 'www.example.com'], $this->recordNames());
    }

    public function testOtherFieldsStillUpdateWhenNoNameIsSent(): void
    {
        $this->assertTrue($this->repository->updateZone(1, ['type' => 'MASTER', 'master' => '192.0.2.53']));

        $this->assertSame('MASTER', (string) $this->db->query("SELECT type FROM domains WHERE id = 1")->fetchColumn());
        $this->assertSame('192.0.2.53', (string) $this->db->query("SELECT master FROM domains WHERE id = 1")->fetchColumn());
    }

    public function testEmptyUpdateStillReportsFailure(): void
    {
        $this->assertFalse($this->repository->updateZone(1, []));
    }
}
