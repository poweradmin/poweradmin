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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;

/**
 * delete() must remove an API key and its zone scopes atomically. If the key row
 * cannot be deleted, the scope rows must survive - otherwise a scoped key would be
 * left with an empty (i.e. unrestricted) scope (audit M25).
 */
class DbApiKeyRepositoryDeleteTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE api_keys (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $this->db->exec("INSERT INTO api_keys (id, name) VALUES (1, 'scoped')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 10), (1, 11)");
    }

    private function repository(): DbApiKeyRepository
    {
        return new DbApiKeyRepository($this->db, $this->createMock(ConfigurationManager::class));
    }

    private function scopeCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM api_key_zones WHERE api_key_id = 1")->fetchColumn();
    }

    public function testDeleteRemovesKeyAndScopes(): void
    {
        $this->assertTrue($this->repository()->delete(1));
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn());
        $this->assertSame(0, $this->scopeCount());
    }

    public function testKeyDeleteFailureKeepsScopes(): void
    {
        // Drop api_keys so the key delete throws after the scope rows are deleted
        // within the transaction; the scopes must be rolled back, not left empty.
        $this->db->exec("DROP TABLE api_keys");

        $this->assertFalse($this->repository()->delete(1));
        $this->assertSame(2, $this->scopeCount());
    }
}
