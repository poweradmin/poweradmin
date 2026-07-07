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

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Model\RecordLog;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * RecordLog must record an edit against the record's real zone, taken from the
 * record itself, not a caller-supplied zone id that may point at a different
 * zone the caller happens to own.
 */
class RecordLogRealZoneTest extends TestCase
{
    private const RECORD_ID = 5;
    private const REAL_ZONE_ID = 2;

    private PDOLayer $db;

    protected function setUp(): void
    {
        $this->db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::RECORD_ID . ", " . self::REAL_ZONE_ID . ", 'www.example.com', 'A', '192.0.2.1', 3600, 0)");
    }

    public function testLogsRecordsRealZoneNotSuppliedId(): void
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(fn(string $key) => $key === 'pdns_db_name' ? '' : null);

        $log = new RecordLog($this->db, $config);
        $log->log_prior(self::RECORD_ID, 999, '');

        $this->assertSame(self::REAL_ZONE_ID, (int)$log->getRecordCopy()['zid']);
    }
}
