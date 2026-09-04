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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use ReflectionClass;

// zones.zone_templ_id must be NOT NULL DEFAULT 0 across all drivers;
// INSERTs rely on the 0 sentinel for "no template".
class ZonesSchemaAlignmentTest extends TestCase
{
    public static function structureFiles(): array
    {
        $root = dirname(__DIR__, 4) . '/sql';
        return [
            'mysql' => [$root . '/poweradmin-mysql-db-structure.sql'],
            'pgsql' => [$root . '/poweradmin-pgsql-db-structure.sql'],
            'sqlite' => [$root . '/poweradmin-sqlite-db-structure.sql'],
        ];
    }

    #[Test]
    #[DataProvider('structureFiles')]
    public function zonesZoneTemplIdIsNotNullWithZeroDefault(string $path): void
    {
        $this->assertFileExists($path);
        $sql = file_get_contents($path);
        $this->assertIsString($sql);

        $createBlock = $this->extractZonesCreate($sql);
        $this->assertNotNull(
            $createBlock,
            "Could not locate CREATE TABLE for zones in $path"
        );

        $line = $this->extractColumnLine($createBlock, 'zone_templ_id');
        $this->assertNotNull(
            $line,
            "zone_templ_id column not found in zones CREATE TABLE in $path"
        );

        $normalized = strtolower(preg_replace('/\s+/', ' ', $line));
        $this->assertStringContainsString(
            'not null',
            $normalized,
            "zones.zone_templ_id must be NOT NULL in $path (got: $line)"
        );
        $this->assertStringContainsString(
            'default 0',
            $normalized,
            "zones.zone_templ_id must declare DEFAULT 0 in $path (got: $line)"
        );
    }

    // Guard against re-introducing the removed createDomain method, which
    // omitted zone_templ_id from its INSERT.
    #[Test]
    public function zoneRepositoryInterfaceDoesNotDeclareCreateDomain(): void
    {
        $reflection = new ReflectionClass(ZoneRepositoryInterface::class);
        $this->assertFalse(
            $reflection->hasMethod('createDomain'),
            'ZoneRepositoryInterface should not declare createDomain (use createZone / DomainManager instead).'
        );
    }

    private function extractZonesCreate(string $sql): ?string
    {
        $startPatterns = [
            '/CREATE TABLE `zones`\s*\(/i',
            '/CREATE TABLE "public"\."zones"\s*\(/i',
            '/CREATE TABLE zones\s*\(/i',
        ];
        foreach ($startPatterns as $pattern) {
            if (preg_match($pattern, $sql, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1] + strlen($m[0][0]);
                return $this->readBalancedBlock($sql, $offset);
            }
        }
        return null;
    }

    private function readBalancedBlock(string $sql, int $start): ?string
    {
        $depth = 1;
        $len = strlen($sql);
        for ($i = $start; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $start, $i - $start);
                }
            }
        }
        return null;
    }

    private function extractColumnLine(string $createBlock, string $column): ?string
    {
        $lines = preg_split('/,(?![^()]*\))/', $createBlock);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $patterns = [
                '/^`' . preg_quote($column, '/') . '`\s/',
                '/^"' . preg_quote($column, '/') . '"\s/',
                '/^' . preg_quote($column, '/') . '\s/',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    return $trimmed;
                }
            }
        }
        return null;
    }
}
