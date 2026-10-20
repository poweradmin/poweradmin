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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteRecordsController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionProperty;
use RuntimeException;

/**
 * After a bulk delete, the page returns to the zone it was posted from only when the
 * user may view that zone, so the redirect never tells whether a foreign id exists.
 */
class DeleteRecordsControllerReturnZoneTest extends TestCase
{
    private const ZONE_ID = 12;

    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
    }

    /** @return array<string, array{string}> */
    public static function paths(): array
    {
        return ['after deleting' => ['deleteRecords'], 'with nothing to delete' => ['showRecords']];
    }

    #[DataProvider('paths')]
    public function testAZoneTheUserMayNotViewSendsThemToTheSearchPage(string $path): void
    {
        $this->assertSame('/search', $this->redirectFor($path, 'own', false));
        $this->assertSame('/search', $this->redirectFor($path, 'none', true));
    }

    #[DataProvider('paths')]
    public function testAZoneTheUserMayViewSendsThemBackToIt(string $path): void
    {
        $this->assertSame('/zones/12/edit', $this->redirectFor($path, 'own', true));
        $this->assertSame('/zones/12/edit', $this->redirectFor($path, 'all', false));
    }

    private function redirectFor(string $path, string $viewLevel, bool $ownsZone): string
    {
        $_POST = ['zone_id' => (string)self::ZONE_ID, 'change_comment' => 'cleanup'];

        $controller = new class ([]) extends DeleteRecordsController {
            use ControllerTestStubs;

            public ?string $redirectedTo = null;

            public function redirect(string $url, array $args = []): void
            {
                $this->redirectedTo = $url;
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }

            public ?SOARecordManagerInterface $soa = null;

            protected function createSOARecordManager(): SOARecordManagerInterface
            {
                return $this->soa ?? throw new RuntimeException('No SOA manager stub');
            }
        };
        $controller->soa = $this->createMock(SOARecordManagerInterface::class);
        $controller->recordManager = $this->createMock(RecordManagerInterface::class);
        $controller->ownsZones = $ownsZone;
        $controller->records = $this->createMock(RecordRepositoryInterface::class);
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getZoneInfoFromId')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com']);
        $controller->domains = $domains;

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->willReturn($viewLevel);
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $values = ['request' => new Request(), 'permissionService' => $permissions, 'userContextService' => $userContext];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(DeleteRecordsController::class, $name))->setValue($controller, $value);
        }

        $controller->$path([]);

        return (string)$controller->redirectedTo;
    }
}
