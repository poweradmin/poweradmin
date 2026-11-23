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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Databases upgraded from 2.x lack zone_content_edit_own_as_client. The 4.6.0 update adds it and
 * grants it to the Editor templates, but only when it was missing, and the PostgreSQL 4.1.0 delete
 * grants match permissions by name so 2.x-era ids still get them. MySQL and PostgreSQL run when the
 * devcontainer is up.
 */
class ClientEditPermissionUpdateScriptIntegrationTest extends TestCase
{
    private const MARKER = '-- Add the edit-as-client permission to databases upgraded from 2.x';

    private static function scratch(): string
    {
        return 'poweradmin_it_clientedit_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::scratch());
                $db->exec('USE ' . self::scratch());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratch());
                $db->exec('SET search_path TO ' . self::scratch());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratch());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /**
     * Permission tables of a 2.x database after the 4.2.0 update, with the 2.x PostgreSQL ids (1-21)
     * on every engine; templates that share a stock name but are custom (other type or description) must not gain the grant
     */
    private function createTables(PDO $db, string $engine): void
    {
        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec("CREATE TABLE perm_items ($id, name VARCHAR(64) NOT NULL, descr VARCHAR(1024) NOT NULL)");
        $db->exec("CREATE TABLE perm_templ ($id, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, template_type VARCHAR(10) NOT NULL DEFAULT 'user')");
        $db->exec("CREATE TABLE perm_templ_items ($id, templ_id INT NOT NULL, perm_id INT NOT NULL)");

        $db->exec("INSERT INTO perm_items (name, descr) VALUES
            ('zone_content_view_own', 'x'), ('zone_content_edit_own', 'x'), ('zone_content_edit_others', 'x')");
        $db->exec("INSERT INTO perm_templ (name, descr, template_type) VALUES
            ('Editor', 'Edit own zone records but cannot modify SOA and NS records.', 'user'),
            ('Editors', 'Edit zone records (no SOA/NS) for group members.', 'group'),
            ('Client', 'x', 'user'), ('Editors', 'x', 'user'), ('Editor', 'x', 'group'),
            ('Editor', 'Custom editor made by an admin', 'user'), ('Editors', 'Custom group editor', 'group')");
        $db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (2, 3), (3, 1), (3, 2)');
    }

    private function section(string $file, string $marker, string $until = "\n\n--"): string
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/' . $file);
        $start = strpos($sql, $marker);
        $this->assertNotFalse($start, "marker missing from $file");
        $end = strpos($sql, $until, $start + strlen($marker));

        return substr($sql, $start, $end === false ? null : $end - $start);
    }

    private function updateSql(string $engine): string
    {
        return $this->section("poweradmin-$engine-update-to-4.6.0.sql", self::MARKER);
    }

    /** @return list<string> template name and type, as "name:type" */
    private function templatesWith(PDO $db, string $perm): array
    {
        $stmt = $db->prepare('SELECT pt.name, pt.template_type FROM perm_templ pt
            JOIN perm_templ_items pti ON pti.templ_id = pt.id
            JOIN perm_items pi ON pi.id = pti.perm_id
            WHERE pi.name = ? ORDER BY pt.name, pt.template_type');
        $stmt->execute([$perm]);

        return array_map(fn(array $row): string => $row[0] . ':' . $row[1], $stmt->fetchAll(PDO::FETCH_NUM));
    }

    #[DataProvider('engines')]
    public function testMissingPermissionIsAddedAndGrantedToEditorTemplates(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);

        $db->exec($this->updateSql($engine));
        $db->exec($this->updateSql($engine));

        $count = (int)$db->query("SELECT COUNT(*) FROM perm_items WHERE name = 'zone_content_edit_own_as_client'")->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertSame(['Editor:user', 'Editors:group'], $this->templatesWith($db, 'zone_content_edit_own_as_client'));
    }

    #[DataProvider('engines')]
    public function testExistingPermissionIsNotGrantedAgain(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);
        $db->exec("INSERT INTO perm_items (name, descr) VALUES ('zone_content_edit_own_as_client', 'x')");

        $db->exec($this->updateSql($engine));

        $this->assertSame([], $this->templatesWith($db, 'zone_content_edit_own_as_client'));
    }

    public function testPgsqlDeleteGrantsMatchPermissionsByName(): void
    {
        $db = $this->connect('pgsql');
        $this->createTables($db, 'pgsql');
        $db->exec("INSERT INTO perm_items (id, name, descr) VALUES (44, 'unrelated_at_44', 'x'),
            (67, 'zone_delete_own', 'x'), (68, 'zone_delete_others', 'x')");
        $db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 44)');

        $db->exec($this->section('poweradmin-pgsql-update-to-4.1.0.sql', '-- Matched by name:', '-- Note:'));

        $this->assertSame(['Client:user'], $this->templatesWith($db, 'zone_delete_own'));
        $this->assertSame(['Editors:group'], $this->templatesWith($db, 'zone_delete_others'));
    }
}
