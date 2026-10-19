<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DatabaseSchemaService;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * users.username is unique in every place a schema comes from: the structure files,
 * the 4.6.0 updates and the web installer.
 */
class UsernameUniqueSchemaTest extends TestCase
{
    private const USER_COLUMNS = "(username, password, fullname, email, description, perm_templ, active, use_ldap)";

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
    public function testSqlFilesDeclareAUniqueUsernameIndex(string $path): void
    {
        $this->assertMatchesRegularExpression(
            '/UNIQUE (KEY|INDEX)( IF NOT EXISTS)? ["`]?idx_users_username["`]?[^;]*\(["`]?username["`]?\)/i',
            (string)file_get_contents($path)
        );
    }

    public function testSqliteStructureRefusesASecondUserWithTheSameName(): void
    {
        $db = $this->sqliteWithStructure();
        $db->exec("INSERT INTO users " . self::USER_COLUMNS . " VALUES ('alice', 'x', '', '', '', 1, 1, 0)");

        $this->expectException(PDOException::class);
        $db->exec("INSERT INTO users " . self::USER_COLUMNS . " VALUES ('alice', 'y', '', '', '', 1, 1, 0)");
    }

    public function testSqliteUpdateFailsOnDuplicatesWithoutTouchingTheRows(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL)");
        $db->exec("INSERT INTO users (username) VALUES ('alice'), ('alice')");

        try {
            $db->exec($this->sqliteUpdateIndexStatement());
            $this->fail('the index was created over duplicate usernames');
        } catch (PDOException) {
            $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM users WHERE username = 'alice'")->fetchColumn());
        }

        $db->exec("UPDATE users SET username = 'alice2' WHERE id = 2");
        $db->exec($this->sqliteUpdateIndexStatement());
        $db->exec($this->sqliteUpdateIndexStatement());
        $this->assertTrue($this->hasUniqueUsernameIndex($db), 'a re-run after cleanup creates the index once');
    }

    public function testInstallerCreatesAUniqueUsernameIndex(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if ($table['table_name'] === 'users') {
                $schema = new DatabaseSchemaService($db);
                $schema->createTable($table['table_name'], $table['fields'], $table['options']);
                $schema->createIndexes($table['table_name'], $table['indexes']);
            }
        }

        $this->assertTrue($this->hasUniqueUsernameIndex($db));
    }

    private function sqliteWithStructure(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-db-structure.sql'));

        return $db;
    }

    private function sqliteUpdateIndexStatement(): string
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-update-to-4.6.0.sql');
        preg_match('/^CREATE UNIQUE INDEX[^;]*idx_users_username[^;]*;/m', $sql, $match);
        $this->assertNotEmpty($match, 'the sqlite update creates idx_users_username');

        return $match[0];
    }

    private function hasUniqueUsernameIndex(PDO $db): bool
    {
        foreach ($db->query('PRAGMA index_list(users)')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ((int)$index['unique'] !== 1) {
                continue;
            }
            $columns = array_column($db->query("PRAGMA index_info({$index['name']})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if ($columns === ['username']) {
                return true;
            }
        }

        return false;
    }
}
