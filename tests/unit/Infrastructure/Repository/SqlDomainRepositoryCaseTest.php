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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * A legacy mixed-case domains row must be found by any spelling of its name,
 * as SQLite and PostgreSQL compare the column case-sensitively.
 */
#[CoversClass(SqlDomainRepository::class)]
#[CoversClass(DbZoneRepository::class)]
class SqlDomainRepositoryCaseTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, master VARCHAR(128), last_check INT, type VARCHAR(8) NOT NULL, notified_serial INT, account VARCHAR(40))");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (7, 'Example.ORG', 'NATIVE')");

        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'database' && $key === 'pdns_db_name') {
                return null;
            }
            if ($group === 'database' && $key === 'type') {
                return 'sqlite';
            }
            return $default;
        });
    }

    #[Test]
    public function domainExistsIgnoresCase(): void
    {
        $repo = new SqlDomainRepository($this->db, $this->config);

        $this->assertTrue($repo->domainExists('example.org'));
        $this->assertTrue($repo->domainExists('EXAMPLE.org'));
        $this->assertFalse($repo->domainExists('other.org'));
    }

    #[Test]
    public function idByNameIgnoresCase(): void
    {
        $repo = new SqlDomainRepository($this->db, $this->config);

        $this->assertSame(7, $repo->getDomainIdByName('example.org'));
        $this->assertSame(7, $repo->getZoneIdFromName('EXAMPLE.ORG'));
    }

    #[Test]
    public function zoneRepositoryIdByNameIgnoresCase(): void
    {
        $repo = new DbZoneRepository($this->db, $this->config);

        $this->assertSame(7, $repo->getZoneIdByName('example.org'));
    }

    #[Test]
    public function exactLowercaseRowWinsOverAMixedCaseTwin(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (12, 'example.org', 'NATIVE')");
        $sql = new SqlDomainRepository($this->db, $this->config);
        $zones = new DbZoneRepository($this->db, $this->config);

        $this->assertSame(12, $sql->getDomainIdByName('Example.ORG'));
        $this->assertSame(12, $sql->getZoneIdFromName('example.org'));
        $this->assertSame(12, $zones->getZoneIdByName('example.org'));
    }
}
