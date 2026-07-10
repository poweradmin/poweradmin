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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * deleteZone() must delete the dependent PowerDNS rows atomically: a mid-sequence
 * failure has to roll back rather than leave a half-deleted zone (audit M23).
 */
class SqlDnsBackendProviderDeleteZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER)");
        $this->db->exec("CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER)");
        $this->db->exec("CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER)");

        $this->db->exec("INSERT INTO domains (id, name) VALUES (1, 'example.com')");
        $this->db->exec("INSERT INTO records (domain_id) VALUES (1), (1)");
        $this->db->exec("INSERT INTO domainmetadata (domain_id) VALUES (1)");
        $this->db->exec("INSERT INTO cryptokeys (domain_id) VALUES (1)");
    }

    private function provider(): SqlDnsBackendProvider
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnMap([['database', 'pdns_db_name', null, '']]);
        return new SqlDnsBackendProvider($this->db, $config, new NullLogger());
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    public function testDeleteZoneRemovesAllDependentRows(): void
    {
        $this->assertTrue($this->provider()->deleteZone(1, 'example.com'));

        $this->assertSame(0, $this->rowCount('domains'));
        $this->assertSame(0, $this->rowCount('records'));
        $this->assertSame(0, $this->rowCount('domainmetadata'));
        $this->assertSame(0, $this->rowCount('cryptokeys'));
    }

    public function testFailureMidSequenceRollsBackEverything(): void
    {
        // Drop cryptokeys so the third delete throws after records + domainmetadata
        // have been deleted within the transaction.
        $this->db->exec("DROP TABLE cryptokeys");

        $this->assertFalse($this->provider()->deleteZone(1, 'example.com'));

        // Nothing may be left half-deleted.
        $this->assertSame(1, $this->rowCount('domains'));
        $this->assertSame(2, $this->rowCount('records'));
        $this->assertSame(1, $this->rowCount('domainmetadata'));
    }
}
