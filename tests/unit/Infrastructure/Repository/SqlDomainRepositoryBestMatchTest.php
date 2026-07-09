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
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * getBestMatchingZoneIdFromName() must match a reverse zone on a label boundary
 * and prefer the most specific zone, not a shorter zone that merely shares a
 * trailing substring (audit H4). Rows are supplied length-DESC, mirroring the
 * repository's ORDER BY length(name) DESC.
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryBestMatchTest extends TestCase
{
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'database' && $key === 'pdns_db_name') {
                return null;
            }
            return $default;
        });
    }

    private function repositoryReturningZones(array $rows): SqlDomainRepository
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $callIndex = 0;
        $stmt->method('fetch')->willReturnCallback(function () use (&$callIndex, $rows) {
            return $rows[$callIndex++] ?? false;
        });

        $this->db->method('prepare')->willReturn($stmt);

        return new SqlDomainRepository($this->db, $this->config);
    }

    #[Test]
    public function ignoresShorterZoneThatIsOnlyASubstringMatch(): void
    {
        // "2.0.192.in-addr.arpa" is a substring of the PTR but not a label-boundary
        // suffix; the record belongs to "0.192.in-addr.arpa".
        $repo = $this->repositoryReturningZones([
            ['name' => '2.0.192.in-addr.arpa', 'id' => 20],
            ['name' => '0.192.in-addr.arpa', 'id' => 30],
        ]);

        $this->assertSame(
            30,
            $repo->getBestMatchingZoneIdFromName('55.12.0.192.in-addr.arpa')
        );
    }

    #[Test]
    public function prefersMostSpecificMatchingZone(): void
    {
        // Both zones are valid suffixes; the longer (most specific) one wins.
        $repo = $this->repositoryReturningZones([
            ['name' => '12.0.192.in-addr.arpa', 'id' => 10],
            ['name' => '0.192.in-addr.arpa', 'id' => 30],
        ]);

        $this->assertSame(
            10,
            $repo->getBestMatchingZoneIdFromName('55.12.0.192.in-addr.arpa')
        );
    }

    #[Test]
    public function returnsMinusOneWhenNoZoneMatches(): void
    {
        $repo = $this->repositoryReturningZones([
            ['name' => '0.192.in-addr.arpa', 'id' => 30],
        ]);

        $this->assertSame(
            -1,
            $repo->getBestMatchingZoneIdFromName('9.9.9.9.in-addr.arpa')
        );
    }
}
