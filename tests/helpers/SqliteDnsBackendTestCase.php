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

namespace TestHelpers;

use PHPUnit\Framework\TestCase;
use PDO;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * Builds a SqlDnsBackendProvider over an in-memory sqlite records table, so
 * validator tests exercise the provider's real SQL instead of a mocked PDO.
 */
abstract class SqliteDnsBackendTestCase extends TestCase
{
    /**
     * @param array<int, array{int, int, string, ?string, string}> $records Rows as [id, domain_id, name, type, content]
     */
    protected function sqliteRecordsDb(array $records = []): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER DEFAULT 3600, prio INTEGER DEFAULT 0, disabled INTEGER DEFAULT 0)");

        $insert = $db->prepare("INSERT INTO records (id, domain_id, name, type, content) VALUES (?, ?, ?, ?, ?)");
        foreach ($records as $row) {
            $insert->execute($row);
        }

        return $db;
    }

    /**
     * @param array<int, array{int, int, string, ?string, string}> $records Rows as [id, domain_id, name, type, content]
     */
    protected function sqliteBackendProvider(array $records = []): SqlDnsBackendProvider
    {
        return new SqlDnsBackendProvider($this->sqliteRecordsDb($records), new FakeConfiguration(), new NullLogger());
    }
}
