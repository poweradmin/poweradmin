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
 * The 4.6.0 updates add the zones.domain_id and zones.owner indexes only when neither
 * name is present: the 3.9.7 update and the installer create them as zones_*_idx.
 */
class ZonesIndexUpdateGuardTest extends TestCase
{
    public static function guards(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $engine) {
            foreach (['domain_id', 'owner'] as $column) {
                $cases["$engine $column"] = [$engine, $column];
            }
        }

        return $cases;
    }

    #[DataProvider('guards')]
    public function testGuardChecksBothIndexNames(string $engine, string $column): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 4) . "/sql/poweradmin-$engine-update-to-4.6.0.sql");

        $this->assertStringContainsString("IN ('idx_zones_$column', 'zones_{$column}_idx')", $sql);
        $this->assertStringNotContainsString("IF NOT EXISTS \"idx_zones_$column\"", $sql);
    }
}
