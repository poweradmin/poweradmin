<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DatabaseSchemaService;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * zone_templ_groups (#1267) exists, unique per template and group and removed with
 * either side, in every place a schema comes from: the structure files, the 4.6.0
 * updates and the web installer.
 */
class ZoneTemplateGroupsSchemaTest extends TestCase
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
    public function testSqlFilesCreateTheTableWithCascadingKeys(string $path): void
    {
        $sql = (string)file_get_contents($path);

        $this->assertMatchesRegularExpression('/CREATE TABLE[^(]*zone_templ_groups["`]?\s*\((.*?)\n\)/s', $sql, "zone_templ_groups missing in $path");
        preg_match('/CREATE TABLE[^(]*zone_templ_groups["`]?\s*\((.*?)\n\)/s', $sql, $match);
        $body = $match[1];
        $this->assertMatchesRegularExpression('/UNIQUE[^(]*\(["`]?zone_templ_id["`]?,\s*["`]?group_id["`]?\)/i', $body);
        $this->assertMatchesRegularExpression('/REFERENCES\s+["`]?(public["`]?\.["`]?)?zone_templ["`]?\s*\(["`]?id["`]?\)\s+ON DELETE CASCADE/i', $body);
        $this->assertMatchesRegularExpression('/REFERENCES\s+["`]?(public["`]?\.["`]?)?user_groups["`]?\s*\(["`]?id["`]?\)\s+ON DELETE CASCADE/i', $body);
    }

    public function testSqliteStructureDropsSharesWithTheTemplateOrTheGroup(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec((string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-db-structure.sql'));
        $db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (1, 'a', '', 2), (2, 'b', '', 2)");
        $db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (103, 'Ops', 1), (104, 'Dev', 1)");
        $db->exec('INSERT INTO zone_templ_groups (zone_templ_id, group_id) VALUES (1, 103), (2, 103), (2, 104)');

        $db->exec('DELETE FROM zone_templ WHERE id = 1');
        $db->exec('DELETE FROM user_groups WHERE id = 104');

        $this->assertSame([[2, 103]], $db->query('SELECT zone_templ_id, group_id FROM zone_templ_groups')->fetchAll(PDO::FETCH_NUM));
    }

    public function testInstallerCreatesTheTable(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if (in_array($table['table_name'], ['zone_templ', 'user_groups', 'zone_templ_groups'], true)) {
                (new DatabaseSchemaService($db))->createTable($table['table_name'], $table['fields'], $table['options']);
                (new DatabaseSchemaService($db))->createIndexes($table['table_name'], $table['indexes'] ?? []);
            }
        }

        $columns = array_column($db->query('PRAGMA table_info(zone_templ_groups)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'zone_templ_id', 'group_id', 'created_at'], $columns);

        $db->exec('INSERT INTO zone_templ_groups (zone_templ_id, group_id) VALUES (1, 3)');
        $this->expectException(\PDOException::class);
        $db->exec('INSERT INTO zone_templ_groups (zone_templ_id, group_id) VALUES (1, 3)');
    }
}
