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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The user, zone and group log pages sort and filter by created_at, so every way a
 * database is built indexes it: the structure files, the installer (as *_created_at_idx)
 * and the 4.6.0 updates, which skip the index when either name is already present.
 */
class LogCreatedAtIndexTest extends TestCase
{
    private const TABLES = ['log_users', 'log_zones', 'log_groups'];

    private static function sql(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 4) . "/sql/$file");
    }

    /** @return array<string, array{string, string}> */
    public static function enginesAndTables(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql', 'sqlite'] as $engine) {
            foreach (self::TABLES as $table) {
                $cases["$engine $table"] = [$engine, $table];
            }
        }

        return $cases;
    }

    #[DataProvider('enginesAndTables')]
    public function testTheStructureFileIndexesCreatedAt(string $engine, string $table): void
    {
        $this->assertStringContainsString("idx_{$table}_created_at", self::sql("poweradmin-$engine-db-structure.sql"));
    }

    #[DataProvider('enginesAndTables')]
    public function testTheUpdateAddsTheIndex(string $engine, string $table): void
    {
        $sql = self::sql("poweradmin-$engine-update-to-4.6.0.sql");

        $this->assertStringContainsString("idx_{$table}_created_at", $sql);
        if ($engine !== 'sqlite') {
            $this->assertStringContainsString("IN ('idx_{$table}_created_at', '{$table}_created_at_idx')", $sql);
        }
    }

    public function testTheInstallerIndexesCreatedAtOnEveryLogTable(): void
    {
        $installer = (string)file_get_contents(dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php');

        foreach (self::TABLES as $table) {
            $this->assertStringContainsString("'{$table}_created_at_idx'", $installer);
        }
    }
}
