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

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Domain\Service\User\PermissionTemplateDeleteResult;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use TestHelpers\SqliteIntegrationTestCase;
use Poweradmin\Infrastructure\Session\PhpSession;
use Poweradmin\Domain\Service\Auth\UserContextService;

/**
 * Deleting a permission template is refused while a user or a group still
 * holds it, and the outcome names which of the two does. The repository
 * reports that outcome to the caller and queues no flash message itself.
 */
#[CoversClass(DbPermissionTemplateRepository::class)]
class DbPermissionTemplateRepositoryDeleteTest extends SqliteIntegrationTestCase
{
    private const TEMPLATE_ID = 7;

    private DbPermissionTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::TEMPLATE_ID . ", 'Spare')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::TEMPLATE_ID . ", 47)");

        $this->repository = new DbPermissionTemplateRepository($this->db, $this->config);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['messages']);
        parent::tearDown();
    }

    private function assignToUser(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (2, 'holder', " . self::TEMPLATE_ID . ")");
    }

    private function assignToGroup(): void
    {
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (1, 'holders', " . self::TEMPLATE_ID . ")");
    }

    private function templateRowCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM perm_templ WHERE id = " . self::TEMPLATE_ID)->fetchColumn();
    }

    /** @return list<string> */
    private function systemErrors(): array
    {
        return array_column((new MessageService(new UserContextService(new PhpSession())))->getMessages('system') ?? [], 'content');
    }

    public function testAnUnassignedTemplateIsDeletedWithItsItems(): void
    {
        $this->assertSame(PermissionTemplateDeleteResult::DELETED, $this->repository->deletePermissionTemplate(self::TEMPLATE_ID));

        $this->assertSame(0, $this->templateRowCount());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM perm_templ_items WHERE templ_id = " . self::TEMPLATE_ID)->fetchColumn());
        $this->assertSame([], $this->systemErrors());
    }

    public function testATemplateHeldByAUserIsKept(): void
    {
        $this->assignToUser();

        $this->assertSame(PermissionTemplateDeleteResult::IN_USE_BY_USERS, $this->repository->deletePermissionTemplate(self::TEMPLATE_ID));

        $this->assertSame(1, $this->templateRowCount());
        $this->assertSame([], $this->systemErrors());
    }

    public function testATemplateHeldByAGroupIsKept(): void
    {
        $this->assignToGroup();

        $this->assertSame(PermissionTemplateDeleteResult::IN_USE_BY_GROUPS, $this->repository->deletePermissionTemplate(self::TEMPLATE_ID));

        $this->assertSame(1, $this->templateRowCount());
        $this->assertSame([], $this->systemErrors());
    }

    public function testATemplateHeldByBothIsKept(): void
    {
        $this->assignToUser();
        $this->assignToGroup();

        $this->assertSame(PermissionTemplateDeleteResult::IN_USE_BY_BOTH, $this->repository->deletePermissionTemplate(self::TEMPLATE_ID));

        $this->assertSame(1, $this->templateRowCount());
        $this->assertSame([], $this->systemErrors());
    }
}
