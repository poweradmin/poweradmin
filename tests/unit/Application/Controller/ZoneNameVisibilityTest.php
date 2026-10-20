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
use Poweradmin\Application\Controller\UnlinkZonesTemplController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Module\Rdap\Controller\RdapController;
use Poweradmin\Module\Whois\Controller\WhoisController;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Pages that take a zone id only name the zone to a user who may view it.
 */
class ZoneNameVisibilityTest extends TestCase
{
    private const ZONE_ID = 12;

    private mixed $savedRequestMethod = null;

    protected function setUp(): void
    {
        $this->savedRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }

    public function testTheUnlinkConfirmationDropsAZoneTheUserDoesNotOwn(): void
    {
        $this->assertSame('No valid zones selected.', $this->unlinkConfirmationError(false, []));
    }

    public function testTheUnlinkConfirmationKeepsAnOwnedZone(): void
    {
        // Past the zone filter, the next step looks the template up
        $this->assertSame('Template not found.', $this->unlinkConfirmationError(true, []));
    }

    public function testTheUnlinkConfirmationKeepsAnyZoneForAnAdmin(): void
    {
        $this->assertSame('Template not found.', $this->unlinkConfirmationError(false, ['user_is_ueberuser']));
    }

    /** @return array<string, array{class-string, string}> */
    public static function lookupPages(): array
    {
        return [
            'whois, id' => [WhoisController::class, 'id'],
            'whois, zone_id' => [WhoisController::class, 'zone_id'],
            'rdap, id' => [RdapController::class, 'id'],
            'rdap, zone_id' => [RdapController::class, 'zone_id'],
        ];
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    public function testALookupPageDoesNotNameAZoneTheUserMayNotView(string $controllerClass, string $param): void
    {
        $this->assertSame('', $this->lookupDomain($controllerClass, $param, 'own', false));
        $this->assertSame('', $this->lookupDomain($controllerClass, $param, 'none', true));
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    public function testALookupPageNamesAZoneTheUserMayView(string $controllerClass, string $param): void
    {
        $this->assertSame('example.com', $this->lookupDomain($controllerClass, $param, 'own', true));
        $this->assertSame('example.com', $this->lookupDomain($controllerClass, $param, 'all', false));
    }

    /**
     * @param string[] $permissions
     */
    private function unlinkConfirmationError(bool $ownsZones, array $permissions): string
    {
        $controller = new class ([]) extends UnlinkZonesTemplController {
            use ControllerTestStubs;
        };
        $controller->ownsZones = $ownsZones;
        $controller->permissions = $permissions;
        (new ReflectionProperty(UnlinkZonesTemplController::class, 'db'))->getValue($controller)
            ->exec('CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            (new ReflectionMethod(UnlinkZonesTemplController::class, 'showConfirmation'))->invoke($controller, [(string)self::ZONE_ID], 3);
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        $this->fail('Expected the request to end in an error page.');
    }

    /**
     * @param class-string $controllerClass
     */
    private function lookupDomain(string $controllerClass, string $param, string $viewLevel, bool $ownsZone): string
    {
        $request = [$param => (string)self::ZONE_ID];
        $controller = $controllerClass === WhoisController::class
            ? new class ($request) extends WhoisController {
                use ControllerTestStubs;

                protected function getCurrentUserId(): int
                {
                    return 7;
                }
            }
            : new class ($request) extends RdapController {
                use ControllerTestStubs;

                protected function getCurrentUserId(): int
                {
                    return 7;
                }
            };
        $controller->ownsZones = $ownsZone;

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->willReturn($viewLevel);
        $controller->permissionServiceStub = $permissions;

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        (new ReflectionProperty($controllerClass, 'domainRepository'))->setValue($controller, $domains);

        return (new ReflectionMethod($controllerClass, 'handleDomainInput'))->invoke($controller);
    }
}
