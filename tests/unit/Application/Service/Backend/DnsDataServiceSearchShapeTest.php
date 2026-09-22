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

namespace Poweradmin\Tests\Unit\Application\Service\Backend;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Backend\DnsDataService;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use TestHelpers\FakeConfiguration;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Pins the exact search result rows DnsDataService hands the search page in each
 * backend mode: the SQL search against an in-memory schema, the API search against
 * a scripted provider. Both include the 'own' view, which reads the session user.
 */
class DnsDataServiceSearchShapeTest extends TestCase
{
    private ArraySession $session;

    private PDO $db;

    protected function setUp(): void
    {
                $this->session = new ArraySession();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)",
                "INSERT INTO users (id, username, fullname) VALUES (7, 'alice', 'Alice'), (8, 'bob', '')",
                "INSERT INTO domains (id, name, type) VALUES (1, 'example.com', 'MASTER'), (2, 'example.net', 'NATIVE')",
                "INSERT INTO zones (id, domain_id, owner, comment) VALUES (11, 1, 7, 'first'), (12, 2, 8, 'second')",
                "INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (100, 1, 'www.example.com', 'A', '192.0.2.1', 3600, 0, 0), (101, 2, 'www.example.net', 'A', '192.0.2.2', 300, 0, 1)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }


    private function parameters(bool $zones, bool $records): array
    {
        return [
            'query' => 'example',
            'zones' => $zones,
            'records' => $records,
            'comments' => false,
            'wildcard' => true,
            'reverse' => false,
            'type_filter' => '',
            'content_filter' => '',
        ];
    }

    private function build(DnsBackendProviderInterface $backend): DnsDataService
    {
        $config = new FakeConfiguration(['database' => ['type' => 'sqlite', 'pdns_db_name' => '']]);

        return new DnsDataService(new RepositoryFactory($this->db, $config, $backend), $backend, $this->db, new SessionActor($this->session));
    }

    private function sqlService(): DnsDataService
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(false);

        return $this->build($backend);
    }

    private function apiService(): DnsDataService
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('searchDnsData')->willReturn([
            'zones' => [
                ['id' => 2, 'name' => 'example.net', 'type' => 'NATIVE'],
                ['id' => 1, 'name' => 'example.com', 'type' => 'MASTER'],
            ],
            'records' => [
                ['id' => 'r2', 'domain_id' => 2, 'name' => 'www.example.net', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => 1, 'zone_name' => 'example.net'],
                ['id' => 'r1', 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
            ],
        ]);
        $backend->method('countZoneRecords')->willReturnCallback(fn(int $id) => $id * 10);

        return $this->build($backend);
    }

    public function testSqlZoneRows(): void
    {
        $rows = $this->sqlService()->searchZones($this->parameters(true, false), 'all', 'name', 'ASC', 10, true, 1);

        $this->assertSame([
            [
                'id' => 1,
                'name' => 'example.com',
                'type' => 'MASTER',
                'zone_id' => 11,
                'domain_id' => 1,
                'owner' => '7',
                'user_id' => 7,
                'fullname' => 'Alice (alice)',
                'username' => 'alice',
                'count_records' => 1,
                'comment' => 'first',
                'owner_fullnames' => ['Alice'],
                'owner_usernames' => ['alice'],
            ],
            [
                'id' => 2,
                'name' => 'example.net',
                'type' => 'NATIVE',
                'zone_id' => 12,
                'domain_id' => 2,
                'owner' => '8',
                'user_id' => 8,
                'fullname' => 'bob',
                'username' => 'bob',
                'count_records' => 1,
                'comment' => 'second',
                'owner_fullnames' => [''],
                'owner_usernames' => ['bob'],
            ],
        ], $rows);
        $this->assertSame(2, $this->sqlService()->searchZonesTotalCount($this->parameters(true, false), 'all'));
    }

    public function testSqlOwnViewFiltersByTheSessionUser(): void
    {
        $this->session->set(SessionKeys::USERID, 8);

        $rows = $this->sqlService()->searchZones($this->parameters(true, false), 'own', 'name', 'ASC', 10, false, 1);
        $records = $this->sqlService()->searchRecords($this->parameters(false, true), 'own', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame([2], array_column($rows, 'id'));
        $this->assertSame([101], array_column($records, 'id'));
        $this->assertSame(1, $this->sqlService()->searchZonesTotalCount($this->parameters(true, false), 'own'));
        $this->assertSame(1, $this->sqlService()->searchRecordsTotalCount($this->parameters(false, true), 'own', false));
    }

    public function testSqlRecordRows(): void
    {
        $rows = $this->sqlService()->searchRecords($this->parameters(false, true), 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame([
            ['id' => 100, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false, 'zone_id' => 11, 'owner' => 7, 'user_id' => 7, 'fullname' => 'Alice'],
            ['id' => 101, 'domain_id' => 2, 'name' => 'www.example.net', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => true, 'zone_id' => 12, 'owner' => 8, 'user_id' => 8, 'fullname' => ''],
        ], $rows);
        $this->assertSame(2, $this->sqlService()->searchRecordsTotalCount($this->parameters(false, true), 'all', false));
    }

    public function testApiZoneRows(): void
    {
        $rows = $this->apiService()->searchZones($this->parameters(true, false), 'all', 'name', 'ASC', 10, true, 1);

        $this->assertSame([
            [
                'id' => 1,
                'name' => 'example.com',
                'type' => 'MASTER',
                'count_records' => 10,
                'user_id' => 7,
                'fullname' => 'Alice (alice)',
                'owner_fullnames' => ['Alice'],
                'owner_usernames' => ['alice'],
                'comment' => 'first',
            ],
            [
                'id' => 2,
                'name' => 'example.net',
                'type' => 'NATIVE',
                'count_records' => 20,
                'user_id' => 8,
                'fullname' => 'bob',
                'owner_fullnames' => [''],
                'owner_usernames' => ['bob'],
                'comment' => 'second',
            ],
        ], $rows);
        $this->assertSame(2, $this->apiService()->searchZonesTotalCount($this->parameters(true, false), 'all'));
    }

    public function testApiRecordRows(): void
    {
        $rows = $this->apiService()->searchRecords($this->parameters(false, true), 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame([
            ['id' => 'r1', 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false, 'user_id' => 7, 'fullname' => 'Alice'],
            ['id' => 'r2', 'domain_id' => 2, 'name' => 'www.example.net', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => true, 'user_id' => 8, 'fullname' => ''],
        ], $rows);
        $this->assertSame(2, $this->apiService()->searchRecordsTotalCount($this->parameters(false, true), 'all', false));
    }

    public function testApiOwnViewFiltersByTheSessionUser(): void
    {
        $this->session->set(SessionKeys::USERID, 8);

        $rows = $this->apiService()->searchZones($this->parameters(true, false), 'own', 'name', 'ASC', 10, false, 1);
        $records = $this->apiService()->searchRecords($this->parameters(false, true), 'own', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame([2], array_column($rows, 'id'));
        $this->assertSame(['r2'], array_column($records, 'id'));
        $this->assertSame(1, $this->apiService()->searchZonesTotalCount($this->parameters(true, false), 'own'));
        $this->assertSame(1, $this->apiService()->searchRecordsTotalCount($this->parameters(false, true), 'own', false));
    }

    public function testApiOwnViewWithoutASessionUserIsEmpty(): void
    {
        $this->session->remove(SessionKeys::USERID);

        $this->assertSame([], $this->apiService()->searchZones($this->parameters(true, false), 'own', 'name', 'ASC', 10, false, 1));
        $this->assertSame([], $this->apiService()->searchRecords($this->parameters(false, true), 'own', 'name', 'ASC', false, 10, false, 1));
    }
}
