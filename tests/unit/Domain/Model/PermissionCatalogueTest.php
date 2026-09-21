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

namespace Poweradmin\Tests\Unit\Domain\Model;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\PermissionCatalogue;
use PoweradminInstall\PermissionHelper;

class PermissionCatalogueTest extends TestCase
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
    public function schemaFileSeedsTheExpectedRows(string $path): void
    {
        $sql = file_get_contents($path);
        $this->assertIsString($sql);

        $permItems = self::parseInsertRows($sql, 'perm_items');
        $this->assertCount(42, $permItems);
        $expectedIds = array_merge(range(41, 65), range(67, 83));
        $this->assertSame($expectedIds, array_column($permItems, 0));

        $templates = self::parseInsertRows($sql, 'perm_templ');
        $this->assertSame(range(1, 10), array_column($templates, 0));
        $this->assertSame(array_fill(0, 5, 'user'), array_column(array_slice($templates, 0, 5), 3));
        $this->assertSame(array_fill(0, 5, 'group'), array_column(array_slice($templates, 5), 3));

        $items = self::parseInsertRows($sql, 'perm_templ_items');
        $this->assertSame(range(1, 56), array_column($items, 0));
        $this->assertSame([1, 53], [$items[0][1], $items[0][2]]);
        $this->assertSame([9, 78], [$items[55][1], $items[55][2]]);

        $groups = self::parseInsertRows($sql, 'user_groups');
        $this->assertSame(range(1, 5), array_column($groups, 0));
        $this->assertSame([6, 7, 8, 9, 10], array_column($groups, 3));
        $this->assertSame([null, null, null, null, null], array_column($groups, 4));
    }

    #[Test]
    #[DataProvider('structureFiles')]
    public function schemaFileSeedRowsMatchTheCatalogue(string $path): void
    {
        $sql = file_get_contents($path);
        $this->assertIsString($sql);

        $this->assertSame(PermissionCatalogue::permissions(), self::parseInsertRows($sql, 'perm_items'));

        $templates = array_map(
            fn(array $t) => [$t['id'], $t['name'], $t['descr'], $t['template_type']],
            PermissionCatalogue::templates()
        );
        $this->assertSame($templates, self::parseInsertRows($sql, 'perm_templ'));

        $this->assertSame(PermissionCatalogue::templateItems(), self::parseInsertRows($sql, 'perm_templ_items'));

        $groups = array_map(
            fn(array $g) => [$g['id'], $g['name'], $g['description'], PermissionCatalogue::templateId($g['template']), null],
            PermissionCatalogue::groups()
        );
        $this->assertSame($groups, self::parseInsertRows($sql, 'user_groups'));
    }

    #[Test]
    public function installerHelperExposesTheCatalogue(): void
    {
        $this->assertSame(PermissionCatalogue::permissions(), PermissionHelper::getPermissionMappings());
    }

    #[Test]
    public function groupTemplatesCarryTheSamePermissionsAsTheirUserTwins(): void
    {
        $user = PermissionCatalogue::templatesOfType('user');
        $group = PermissionCatalogue::templatesOfType('group');
        $this->assertCount(5, $user);
        $this->assertCount(5, $group);

        foreach ($user as $i => $template) {
            $this->assertSame($template['permissions'], $group[$i]['permissions'], $template['name']);
        }
        $this->assertSame([Permission::PERM_USER_IS_UEBERUSER], $user[0]['permissions']);
        $this->assertSame([], $user[4]['permissions']);
    }

    #[Test]
    public function everyTemplatePermissionExistsInTheCatalogue(): void
    {
        $names = array_column(PermissionCatalogue::permissions(), 1);
        $this->assertSame($names, array_unique($names));

        foreach (PermissionCatalogue::templates() as $template) {
            foreach ($template['permissions'] as $permission) {
                $this->assertContains($permission, $names, $template['name']);
            }
        }
        foreach (PermissionCatalogue::groups() as $group) {
            $this->assertSame('group', PermissionCatalogue::templatesOfType('group')[$group['id'] - 1]['template_type']);
            $this->assertSame($group['template'], PermissionCatalogue::templatesOfType('group')[$group['id'] - 1]['name']);
        }
    }

    #[Test]
    public function unknownNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PermissionCatalogue::permissionId('no_such_permission');
    }

    /**
     * Returns the value tuples of every INSERT INTO <table> in the file, whether it
     * uses one multi-row statement or one statement per row. Integers come back as
     * int, quoted strings unquoted, NULL as null.
     *
     * @return array<int, array<int, int|string|null>>
     */
    public static function parseInsertRows(string $sql, string $table): array
    {
        $pattern = '/INSERT INTO [`"]?' . preg_quote($table, '/') . '[`"]? \([^)]*\) VALUES\s*(.*?);/s';
        preg_match_all($pattern, $sql, $statements);

        $rows = [];
        foreach ($statements[1] as $valuesClause) {
            preg_match_all("/\\((?:[^()']|'(?:[^']|'')*')*\\)/", $valuesClause, $tuples);
            foreach ($tuples[0] as $tuple) {
                $rows[] = self::parseTuple($tuple);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, int|string|null>
     */
    private static function parseTuple(string $tuple): array
    {
        preg_match_all("/'((?:[^']|'')*)'|NULL|-?\\d+/", $tuple, $matches, PREG_SET_ORDER);

        return array_map(function (array $m) {
            if (isset($m[1])) {
                return str_replace("''", "'", $m[1]);
            }
            return $m[0] === 'NULL' ? null : (int)$m[0];
        }, $matches);
    }
}
