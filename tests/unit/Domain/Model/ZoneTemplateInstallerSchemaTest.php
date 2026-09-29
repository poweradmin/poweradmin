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

use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DatabaseSchemaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use PoweradminInstall\DatabaseStructureHelper;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The 4.4 web installer created zone_templ.is_default as NOT NULL without a default,
 * so template creation must not depend on the column default (#1605).
 */
class ZoneTemplateInstallerSchemaTest extends SqliteIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('CREATE TABLE zone_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, owner integer NOT NULL, created_by integer, is_default boolean NOT NULL)');
        $this->db->exec('CREATE TABLE zone_templ_records (id integer PRIMARY KEY, zone_templ_id integer NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(6) NOT NULL, content VARCHAR(2048) NOT NULL, ttl integer NOT NULL, prio integer NOT NULL)');
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_templ_add')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (' . self::ADMIN_PERM_TEMPL_ID . ', 60)');
    }

    public function testAddingATemplateWritesIsDefaultExplicitly(): void
    {
        $template = new ZoneTemplate($this->db, $this->config);

        $this->assertTrue($template->addZoneTempl(['templ_name' => 'First', 'templ_descr' => 'first template'], self::ADMIN_USER_ID));
        $this->assertSame(['First', 0], $this->storedTemplate('First'));
    }

    public function testSaveAsTemplateWritesIsDefaultExplicitly(): void
    {
        $template = new ZoneTemplate($this->db, $this->config);

        $this->assertTrue($template->addZoneTemplSaveAs('Copy', 'saved from a zone', self::ADMIN_USER_ID, [], []));
        $this->assertSame(['Copy', 0], $this->storedTemplate('Copy'));
    }

    public function testRepositoryCreateWritesIsDefaultExplicitly(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn (string $group, string $key, mixed $default = null) => $this->config->get($group, $key, $default));
        $repository = new DbZoneTemplateRepository($this->db, $config);

        $repository->createZoneTemplate('Api', 'created through the API', 0, self::ADMIN_USER_ID);
        $this->assertSame(['Api', 0], $this->storedTemplate('Api'));
    }

    public function testInstallerSchemaGivesIsDefaultADefault(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $this->db->exec('DROP TABLE zone_templ');

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if ($table['table_name'] === 'zone_templ') {
                (new DatabaseSchemaService($this->db))->createTable('zone_templ', $table['fields'], $table['options']);
            }
        }

        $this->db->exec("INSERT INTO zone_templ (name, descr, owner, created_by) VALUES ('Bare', '', 0, 1)");
        $this->assertSame(['Bare', 0], $this->storedTemplate('Bare'));
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function storedTemplate(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT name, is_default FROM zone_templ WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : [$row['name'], (int) $row['is_default']];
    }
}
