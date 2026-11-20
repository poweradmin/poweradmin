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

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\UnlinkZonesTemplController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use RuntimeException;

/**
 * Unlinking a zone from its template is a zone meta-edit: the level applies to ownership the
 * way it does on the zone editor, and content edit alone does not open it.
 */
#[CoversClass(UnlinkZonesTemplController::class)]
class UnlinkZonesTemplControllerMetaEditTest extends TestCase
{
    private const USER_ID = 7;
    private const OWN_ZONE = 50;
    private const FOREIGN_ZONE = 60;

    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO zone_templ (id, name, owner) VALUES (5, 'shared', 0)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER NOT NULL)",
                "CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)",
                "CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT, zone_id INTEGER, priority INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
                "INSERT INTO perm_items (id, name) VALUES (1, 'zone_content_edit_own'), (2, 'zone_meta_edit_own'), (3, 'zone_meta_edit_others'), (4, 'user_is_ueberuser')",
                "INSERT INTO perm_templ (id, name) VALUES (1, 'content only'), (2, 'meta own'), (3, 'meta others'), (4, 'admin')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (2, 1), (2, 2), (3, 3), (4, 4)",
                "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . self::OWN_ZONE . ", " . self::USER_ID . ", 5), (" . self::FOREIGN_ZONE . ", 9, 5)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $_SESSION = ['userid' => self::USER_ID];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public static function levels(): array
    {
        return [
            'content edit only' => [1, [], []],
            'meta edit own' => [2, [self::OWN_ZONE], [self::OWN_ZONE]],
            'meta edit others' => [3, [self::OWN_ZONE, self::FOREIGN_ZONE], [self::OWN_ZONE, self::FOREIGN_ZONE]],
            'superuser' => [4, [self::OWN_ZONE, self::FOREIGN_ZONE], [self::OWN_ZONE, self::FOREIGN_ZONE]],
        ];
    }

    /**
     * @param list<int> $unlinked
     * @param list<int> $confirmed
     */
    #[RunInSeparateProcess]
    #[DataProvider('levels')]
    public function testUnlinkAndConfirmationFollowTheMetaEditLevel(int $template, array $unlinked, array $confirmed): void
    {
        $this->db->exec("INSERT INTO users (id, perm_templ) VALUES (" . self::USER_ID . ", $template)");
        $controller = $this->controller();

        // The confirmation step keeps only zones the unlink step would accept
        try {
            (new ReflectionMethod($controller, 'showConfirmation'))->invoke($controller, [self::OWN_ZONE, self::FOREIGN_ZONE], 5);
            $this->fail('Expected the page to end.');
        } catch (RuntimeException $e) {
            $this->assertSame($confirmed === [] ? 'No valid zones selected.' : 'rendered', $e->getMessage());
        }

        try {
            (new ReflectionMethod($controller, 'unlinkZones'))->invoke($controller, [self::OWN_ZONE, self::FOREIGN_ZONE], 5);
            $this->fail('Expected a redirect.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('redirect', $e->getMessage());
        }

        $rows = $this->db->query("SELECT domain_id FROM zones WHERE zone_templ_id = 0 ORDER BY domain_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame($unlinked, array_map('intval', $rows));
    }

    private function controller(): UnlinkZonesTemplController
    {
        $controller = new class ([], $this->db, $this->createMock(ConfigurationManager::class)) extends UnlinkZonesTemplController {
            use ControllerTestStubs;

            public function render(string $template, array $params): void
            {
                throw new RuntimeException('rendered');
            }

            public function redirect(string $url, array $args = []): void
            {
                throw new RuntimeException('redirect');
            }

            protected function createDnsBackendProvider(): \Poweradmin\Domain\Service\DnsBackendProvider
            {
                return new class () extends \Poweradmin\Infrastructure\Service\SqlDnsBackendProvider {
                    public function __construct()
                    {
                    }
                };
            }
        };

        return $controller;
    }
}
