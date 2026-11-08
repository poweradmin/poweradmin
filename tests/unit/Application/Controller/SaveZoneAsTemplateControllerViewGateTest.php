<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\SaveZoneAsTemplateController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/ControllerTestStubs.php';

/**
 * Saving a zone as a template reads its records, so it needs a view level that
 * lets the caller see the zone's content, not just ownership.
 */
#[CoversClass(SaveZoneAsTemplateController::class)]
class SaveZoneAsTemplateControllerViewGateTest extends TestCase
{
    private const ZONE_ID = 42;
    private const USER_ID = 7;

    private array $sessionBackup = [];
    private ?string $methodBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = ['userid' => self::USER_ID];
        $this->methodBackup = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        if ($this->methodBackup === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->methodBackup;
        }
        parent::tearDown();
    }

    public static function cases(): array
    {
        return [
            'owner with view none' => ['none', true, 'You do not have permission to access this zone.'],
            'non-owner with view own' => ['own', false, 'You do not have permission to access this zone.'],
            'owner with view own' => ['own', true, 'rendered'],
            'view all' => ['all', false, 'rendered'],
            // The zone edit page uses the user's own level, so a group-only owner whose own template views opens it
            'owner, group level none, own level own' => ['none', true, 'rendered', 'own'],
        ];
    }

    #[DataProvider('cases')]
    public function testViewLevelDecidesAccess(string $level, bool $owner, string $expected, ?string $ownLevel = null): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)');
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $db->exec('CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)');
        if ($owner) {
            $db->exec('INSERT INTO zones (domain_id, owner) VALUES (' . self::ZONE_ID . ', ' . self::USER_ID . ')');
        }

        $controller = new class (['id' => (string)self::ZONE_ID], $db, $this->createMock(ConfigurationManager::class)) extends SaveZoneAsTemplateController {
            use ControllerTestStubs;

            public function render(string $template, array $params): void
            {
                throw new RuntimeException('rendered');
            }

            protected function setCurrentPage(string $page): void
            {
            }

            protected function setPageTitle(string $title): void
            {
            }
        };

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canAddZoneTemplates')->willReturn(true);
        $permissionService->method('isAdmin')->willReturn(false);
        $permissionService->method('getViewPermissionLevelForZone')->willReturn($level);
        $permissionService->method('getViewPermissionLevel')->willReturn($ownLevel ?? $level);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturn('example.com');
        $zones->method('getDomainType')->willReturn('MASTER');

        foreach (
            [
                'userContextService' => new UserContextService(),
                'zoneRepository' => $zones,
                'permissionService' => $permissionService,
            ] as $name => $value
        ) {
            (new ReflectionProperty(SaveZoneAsTemplateController::class, $name))->setValue($controller, $value);
        }

        try {
            $controller->run();
            $this->fail('Expected the request to end.');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getMessage());
        }
    }
}
