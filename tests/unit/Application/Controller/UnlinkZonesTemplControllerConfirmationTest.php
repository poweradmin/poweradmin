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
use Poweradmin\Application\Controller\UnlinkZonesTemplController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The unlink confirmation page names the template, so it answers "not found"
 * for a template the caller cannot see in the template list.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class UnlinkZonesTemplControllerConfirmationTest extends SqliteIntegrationTestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const ZONE_ID = 12;
    private const OWN_TEMPLATE = 1;
    private const FOREIGN_TEMPLATE = 2;
    private const GLOBAL_TEMPLATE = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES
            (" . self::OWN_TEMPLATE . ", 'own', '', " . self::USER_ID . "),
            (" . self::FOREIGN_TEMPLATE . ", 'foreign-secret', '', 999),
            (" . self::GLOBAL_TEMPLATE . ", 'global', '', 0)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Tester')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'tester', " . self::PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::ZONE_ID . ", " . self::USER_ID . ")");
        $this->db->exec("INSERT INTO perm_items (name) VALUES ('zone_content_edit_own')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::PERM_TEMPL_ID . ", " . $this->db->lastInsertId() . ")");

        $_SESSION['userid'] = self::USER_ID;
    }

    #[RunInSeparateProcess]
    public function testAForeignTemplateIsNotNamed(): void
    {
        $this->assertSame('Template not found.', $this->confirmationError(self::FOREIGN_TEMPLATE));
    }

    #[RunInSeparateProcess]
    public function testAMissingTemplateGetsTheSameAnswer(): void
    {
        $this->assertSame('Template not found.', $this->confirmationError(404));
    }

    #[RunInSeparateProcess]
    public function testOwnAndGlobalTemplatesAreNotRefused(): void
    {
        $this->assertNotSame('Template not found.', $this->confirmationError(self::OWN_TEMPLATE));
        $this->assertNotSame('Template not found.', $this->confirmationError(self::GLOBAL_TEMPLATE));
    }

    #[RunInSeparateProcess]
    public function testAForeignTemplateLinkedToTheOwnZoneIsNotRefused(): void
    {
        $this->db->exec("UPDATE zones SET zone_templ_id = " . self::FOREIGN_TEMPLATE . " WHERE domain_id = " . self::ZONE_ID);

        $this->assertNotSame('Template not found.', $this->confirmationError(self::FOREIGN_TEMPLATE));
    }

    private function confirmationError(int $templateId): ?string
    {
        $config = ConfigurationManager::getInstance();
        $controller = new class ([], $this->db, $config) extends UnlinkZonesTemplController {
            use ControllerTestStubs;
        };

        try {
            (new ReflectionMethod(UnlinkZonesTemplController::class, 'showConfirmation'))
                ->invoke($controller, [self::ZONE_ID], $templateId);
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        } catch (\Throwable) {
            // Past the access check the unwired controller trips on its missing services.
            return null;
        }

        return null;
    }
}
