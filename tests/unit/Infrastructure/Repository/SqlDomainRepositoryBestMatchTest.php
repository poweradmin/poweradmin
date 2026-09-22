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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * getBestMatchingZoneIdFromName() must match a reverse zone on a label boundary
 * and prefer the most specific zone, not a shorter zone that merely shares a
 * trailing substring (audit H4).
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryBestMatchTest extends SqliteIntegrationTestCase
{
    private SqlDomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (10, '12.0.192.in-addr.arpa', 'MASTER'),
            (20, '2.0.192.in-addr.arpa', 'MASTER'),
            (30, '0.192.in-addr.arpa', 'MASTER'),
            (40, 'example.com', 'MASTER')");

        $this->repository = new SqlDomainRepository($this->db, $this->config);
    }

    #[Test]
    public function prefersTheMostSpecificMatchingZone(): void
    {
        $this->assertSame(10, $this->repository->getBestMatchingZoneIdFromName('55.12.0.192.in-addr.arpa'));
    }

    #[Test]
    public function ignoresAShorterZoneThatIsOnlyASubstringMatch(): void
    {
        // "2.0.192.in-addr.arpa" is a substring of the PTR but not a label-boundary
        // suffix, so the record belongs to "0.192.in-addr.arpa"
        $this->db->exec("DELETE FROM domains WHERE id = 10");

        $this->assertSame(30, $this->repository->getBestMatchingZoneIdFromName('55.12.0.192.in-addr.arpa'));
    }

    #[Test]
    public function returnsMinusOneWhenNoZoneMatches(): void
    {
        $this->assertSame(-1, $this->repository->getBestMatchingZoneIdFromName('9.9.9.9.in-addr.arpa'));
    }

    #[Test]
    public function ignoresForwardZonesEntirely(): void
    {
        $this->assertSame(-1, $this->repository->getBestMatchingZoneIdFromName('www.example.com'));
    }
}
