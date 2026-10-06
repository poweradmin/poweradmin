<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DatabaseSchemaService;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * users.max_zones and user_groups.max_zones (#72) exist, nullable, in every place a
 * schema comes from: the structure files, the 4.6.0 updates and the web installer.
 */
class ZoneLimitSchemaTest extends TestCase
{
    public static function sqlFiles(): array
    {
        $root = dirname(__DIR__, 4) . '/sql';
        $files = [];
        foreach (['mysql', 'pgsql', 'sqlite'] as $engine) {
            $files["$engine structure"] = [$root . "/poweradmin-$engine-db-structure.sql"];
            $files["$engine update"] = [$root . "/poweradmin-$engine-update-to-4.6.0.sql"];
        }

        return $files;
    }

    #[DataProvider('sqlFiles')]
    public function testSqlFilesAddANullableColumnToBothTables(string $path): void
    {
        $sql = (string)file_get_contents($path);

        foreach (['users', 'user_groups'] as $table) {
            $this->assertMatchesRegularExpression(
                '/' . $table . '\b[^;]*?["`]?max_zones["`]?\s+(int|integer)(\(\d+\))?\s+DEFAULT NULL/is',
                $sql,
                "$table.max_zones missing or not nullable in $path"
            );
        }
    }

    public function testInstallerCreatesNullableColumns(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if (in_array($table['table_name'], ['users', 'user_groups'], true)) {
                (new DatabaseSchemaService($db))->createTable($table['table_name'], $table['fields'], $table['options']);
            }
        }

        foreach (['users', 'user_groups'] as $table) {
            $columns = array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), null, 'name');
            $this->assertArrayHasKey('max_zones', $columns, "installer $table lacks max_zones");
            $this->assertSame(0, (int)$columns['max_zones']['notnull']);
            $this->assertNull($columns['max_zones']['dflt_value']);
        }
    }
}
