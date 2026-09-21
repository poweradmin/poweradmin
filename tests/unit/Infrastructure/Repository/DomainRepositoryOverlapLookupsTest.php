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

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * The overlap-guard lookups read the domains table in SQL mode and the local
 * zones table in API mode, with the same matching rules on both.
 */
#[CoversClass(SqlDomainRepository::class)]
#[CoversClass(ApiDomainRepository::class)]
class DomainRepositoryOverlapLookupsTest extends TestCase
{
    private function sqlRepository(): DomainRepositoryInterface
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, master TEXT, type TEXT)");
        $db->exec("INSERT INTO domains (id, name) VALUES
            (14, 'A.CoM'),
            (15, 'b.a.com'),
            (16, 'c_d.a.com'),
            (17, 'cxd.a.com'),
            (18, 'other.com'),
            (19, 'xa.com')");

        return new SqlDomainRepository($db, $this->createMock(ConfigurationManager::class));
    }

    private function apiRepository(): DomainRepositoryInterface
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name TEXT)");
        // Row 14 was migrated from SQL mode and keeps its domain_id; the rest were created here
        $db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES
            (1, 14, 'A.CoM'),
            (15, NULL, 'b.a.com'),
            (16, NULL, 'c_d.a.com'),
            (17, NULL, 'cxd.a.com'),
            (18, NULL, 'other.com'),
            (19, NULL, 'xa.com')");

        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->method('allocatesZoneIdsLocally')->willReturn(true);

        return new ApiDomainRepository($db, $this->createMock(ConfigurationManager::class), $provider);
    }

    /** @return iterable<string, array{0: callable(self): DomainRepositoryInterface}> */
    public static function repositories(): iterable
    {
        yield 'sql' => [fn(self $test) => $test->sqlRepository()];
        yield 'api' => [fn(self $test) => $test->apiRepository()];
    }

    #[Test]
    #[DataProvider('repositories')]
    public function findZoneIdsByNamesMatchesCaseInsensitivelyAndKeysByTheStoredName(callable $build): void
    {
        $repository = $build($this);

        $this->assertSame(['A.CoM' => 14, 'other.com' => 18], $repository->findZoneIdsByNames(['a.com', 'other.com', 'missing.com']));
        $this->assertSame([], $repository->findZoneIdsByNames([]));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function findZonesUnderReturnsOnlyTrueSubdomainsOrderedByName(callable $build): void
    {
        $repository = $build($this);

        $this->assertSame(
            [['id' => 15, 'name' => 'b.a.com'], ['id' => 16, 'name' => 'c_d.a.com'], ['id' => 17, 'name' => 'cxd.a.com']],
            $repository->findZonesUnder('a.com')
        );
    }

    #[Test]
    #[DataProvider('repositories')]
    public function findZonesUnderTreatsUnderscoreLiterally(callable $build): void
    {
        $repository = $build($this);

        // An unescaped "_" would match any character, so a.co_ would pull in every *.a.com
        $this->assertSame([], $repository->findZonesUnder('c_d.a.com'));
        $this->assertSame([], $repository->findZonesUnder('a.co_'));
    }
}
