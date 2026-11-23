<?php

namespace unit\Sql;

use PDO;
use PHPUnit\Framework\TestCase;

class Update410StockGrantsTest extends TestCase
{
    private function run410(array $existing): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL)');
        $pdo->exec('CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64) NOT NULL, descr VARCHAR(1024) NOT NULL)');
        $pdo->exec('CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL)');
        $items = ['zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own', 'zone_meta_edit_own',
            'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit', 'api_manage_keys', 'zone_delete_own',
            'zone_content_edit_own_as_client'];
        foreach ($items as $name) {
            $pdo->prepare('INSERT INTO perm_items (name, descr) VALUES (?, ?)')->execute([$name, $name]);
        }
        foreach ($existing as $name => $perms) {
            $pdo->prepare('INSERT INTO perm_templ (name, descr) VALUES (?, ?)')->execute([$name, 'custom']);
            $id = (int) $pdo->lastInsertId();
            foreach ($perms as $perm) {
                $pdo->prepare('INSERT INTO perm_templ_items (templ_id, perm_id) SELECT ?, id FROM perm_items WHERE name = ?')
                    ->execute([$id, $perm]);
            }
        }

        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/sql/poweradmin-sqlite-update-to-4.1.0.sql');
        $start = strpos($sql, '-- Add standard permission templates');
        $this->assertNotFalse($start);
        $pdo->exec(substr($sql, $start));

        return $pdo;
    }

    private function grants(PDO $pdo, string $template): array
    {
        $stmt = $pdo->prepare('SELECT pi.name FROM perm_templ pt JOIN perm_templ_items pti ON pti.templ_id = pt.id '
            . 'JOIN perm_items pi ON pi.id = pti.perm_id WHERE pt.name = ? ORDER BY pi.name');
        $stmt->execute([$template]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testCustomTemplateWithStockNameKeepsItsOwnGrants(): void
    {
        $pdo = $this->run410(['Zone Manager' => ['zone_content_view_own'], 'Read Only' => ['search']]);

        $this->assertSame(['zone_content_view_own'], $this->grants($pdo, 'Zone Manager'));
        $this->assertSame(['search'], $this->grants($pdo, 'Read Only'));
    }

    public function testFreshStockTemplatesStillReceiveGrants(): void
    {
        $pdo = $this->run410([]);

        $this->assertCount(11, $this->grants($pdo, 'Zone Manager'));
        $this->assertCount(4, $this->grants($pdo, 'DNS Editor'));
        $this->assertCount(2, $this->grants($pdo, 'Read Only'));
        $this->assertSame([], $this->grants($pdo, 'No Access'));
    }
}
