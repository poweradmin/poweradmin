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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Controller\DeleteRecordsController;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use ReflectionProperty;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The bulk delete confirmation looks the record's zone up only for a user who may view
 * zones at all: for anyone else the lookup flashes a refusal, which would tell an existing
 * record from a missing one.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class DeleteRecordsControllerConfirmationTest extends SqliteIntegrationTestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const ZONE_ID = 12;
    private const RECORD_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::ZONE_ID . ", 'example.com', 'MASTER')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::RECORD_ID . ", " . self::ZONE_ID . ", 'www.example.com', 'A', '192.0.2.1', 3600, 0)");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::ZONE_ID . ", " . self::USER_ID . ")");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Tester')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'tester', " . self::PERM_TEMPL_ID . ")");

        $_SESSION['userid'] = self::USER_ID;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    #[RunInSeparateProcess]
    public function testAUserWhoMayViewNoZoneGetsNoZoneRefusalForAnExistingRecord(): void
    {
        $this->grant(['zone_content_edit_own']);

        $this->showRecords();

        $this->assertNotContains('You do not have the permission to view this zone.', $this->systemErrors());
    }

    #[RunInSeparateProcess]
    public function testAnOwnerWhoMayViewTheZoneStillGetsTheConfirmation(): void
    {
        $this->grant(['zone_content_view_own', 'zone_content_edit_own']);

        $controller = $this->showRecords();

        $this->assertSame('delete_records.html', $controller->rendered[0] ?? null);
        $this->assertSame([self::RECORD_ID], array_map('intval', array_column($controller->rendered[1]['records'] ?? [], 'id')));
        $this->assertSame([], $this->systemErrors());
    }

    /**
     * @param string[] $permissions
     */
    private function grant(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->db->prepare("INSERT INTO perm_items (name) VALUES (?)")->execute([$permission]);
            $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (?, ?)")
                ->execute([self::PERM_TEMPL_ID, $this->db->lastInsertId()]);
        }
    }

    private function showRecords(): object
    {
        $controller = new class ([], $this->db, $this->primeConfig()) extends DeleteRecordsController {
            use ControllerTestStubs;

            /** @var array{0?: string, 1?: array<string, mixed>} */
            public array $rendered = [];

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }

            public function render(string $template, array $params): void
            {
                $this->rendered = [$template, $params];
            }
        };
        (new ReflectionProperty(DeleteRecordsController::class, 'userContextService'))->setValue($controller, new UserContextService());

        $controller->showRecords([self::RECORD_ID]);

        return $controller;
    }

    /**
     * @return string[]
     */
    private function systemErrors(): array
    {
        return array_column($_SESSION['messages']['system'] ?? [], 'content');
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
