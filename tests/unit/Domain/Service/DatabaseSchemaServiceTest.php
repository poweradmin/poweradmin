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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DatabaseSchemaService;

#[CoversClass(DatabaseSchemaService::class)]
class DatabaseSchemaServiceTest extends TestCase
{
    private PDO $db;
    private DatabaseSchemaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->service = new DatabaseSchemaService($this->db);
    }

    // `default => 0` is a historical placeholder meaning "no DEFAULT emitted";
    // keep that working for the ~50 fields that already rely on it.
    #[Test]
    public function defaultZeroWithoutOptInDoesNotEmitDefaultClause(): void
    {
        $this->service->createTable('placeholder_default', [
            'id' => [
                'type' => 'integer',
                'flags' => 'primary_keynot_null',
            ],
            'value' => [
                'type' => 'integer',
                'notnull' => 1,
                'default' => 0,
            ],
        ]);

        $sql = $this->getCreateSql('placeholder_default');
        $this->assertStringNotContainsString('DEFAULT 0', $sql, "Expected no DEFAULT clause for placeholder zero: $sql");
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    #[Test]
    public function defaultZeroWithEmitDefaultOptInEmitsDefaultClause(): void
    {
        $this->service->createTable('explicit_default', [
            'id' => [
                'type' => 'integer',
                'flags' => 'primary_keynot_null',
            ],
            'value' => [
                'type' => 'integer',
                'notnull' => 1,
                'default' => 0,
                'emit_default' => true,
            ],
        ]);

        $sql = $this->getCreateSql('explicit_default');
        $this->assertStringContainsString('DEFAULT 0', $sql, "Expected DEFAULT 0 clause with emit_default: $sql");
    }

    private function getCreateSql(string $table): string
    {
        $stmt = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $this->db->quote($table));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['sql'] ?? '';
    }
}
