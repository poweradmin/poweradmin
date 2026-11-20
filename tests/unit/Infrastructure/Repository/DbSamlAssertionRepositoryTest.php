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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DatabaseSchemaService;
use Poweradmin\Infrastructure\Repository\DbSamlAssertionRepository;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * The used-assertion store (saml_assertion_ids): an ID is accepted once, expired IDs are
 * forgotten, and the table exists in every place a schema comes from.
 */
#[CoversClass(DbSamlAssertionRepository::class)]
final class DbSamlAssertionRepositoryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec((string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-db-structure.sql'));
    }

    public function testAnAssertionIdIsAcceptedOnceAndRefusedAfterwards(): void
    {
        $store = new DbSamlAssertionRepository($this->db);

        $this->assertTrue($store->consume('_abc', time() + 300));
        $this->assertFalse($store->consume('_abc', time() + 300));
        $this->assertTrue($store->consume('_other', time() + 300));
    }

    public function testIdsAreComparedByteExact(): void
    {
        $store = new DbSamlAssertionRepository($this->db);

        $this->assertTrue($store->consume('_Abc', time() + 300));
        $this->assertTrue($store->consume('_abc', time() + 300));
    }

    public function testExpiredIdsAreForgottenWhenAnotherIsRecorded(): void
    {
        $store = new DbSamlAssertionRepository($this->db);
        $this->assertTrue($store->consume('_old', time() - 10));
        $this->assertTrue($store->consume('_new', time() + 300));

        $this->assertSame(['_new'], $this->db->query('SELECT assertion_id FROM saml_assertion_ids')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertTrue($store->consume('_old', time() + 300));
    }

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
    public function testSqlFilesCreateTheTableWithAUniqueAssertionId(string $path): void
    {
        $sql = (string)file_get_contents($path);

        $this->assertSame(1, preg_match('/CREATE TABLE[^(]*saml_assertion_ids["`]?\s*\((.*?)\n\)/s', $sql, $match), "saml_assertion_ids missing in $path");
        $this->assertMatchesRegularExpression('/UNIQUE/i', $match[1]);
        $this->assertMatchesRegularExpression('/not_on_or_after/', $match[1]);
    }

    public function testInstallerCreatesTheTable(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if ($table['table_name'] === 'saml_assertion_ids') {
                (new DatabaseSchemaService($db))->createTable($table['table_name'], $table['fields'], $table['options']);
                (new DatabaseSchemaService($db))->createIndexes($table['table_name'], $table['indexes'] ?? []);
            }
        }

        $this->assertTrue((new DbSamlAssertionRepository($db))->consume('_abc', time() + 300));
        $this->assertFalse((new DbSamlAssertionRepository($db))->consume('_abc', time() + 300));
    }
}
