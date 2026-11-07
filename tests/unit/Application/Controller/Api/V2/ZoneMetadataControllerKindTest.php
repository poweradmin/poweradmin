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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * A kind that only collates equal to an operator-only one (ENABLÉ-LUA-RECORDS under latin1)
 * is refused before the exact operator-only lookup can be bypassed.
 */
#[CoversClass(ZoneMetadataController::class)]
class ZoneMetadataControllerKindTest extends TestCase
{
    public static function writeProvider(): array
    {
        return [
            'put' => ['updateMetadataKind'],
            'delete' => ['deleteMetadataKind'],
        ];
    }

    #[DataProvider('writeProvider')]
    public function testANonAsciiKindIsRefusedForAnEditorBeforeAnyWrite(string $method): void
    {
        $this->zones->expects($this->never())->method('replaceDomainMetadata');

        foreach (['ENABLÉ-LUA-RECORDS', "ENABLE-LUA-RECORDS\u{200B}", 'LUA-AXFR-SCRİPT'] as $kind) {
            $response = $this->call($method, $kind);

            $this->assertSame(400, $response->getStatusCode(), $kind);
            $this->assertStringContainsString('Invalid metadata kind', (string)$response->getContent());
        }
    }

    #[DataProvider('writeProvider')]
    public function testTheRealOperatorOnlyKindStillNeedsAnAdministrator(string $method): void
    {
        $response = $this->call($method, 'enable-lua-records');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('can only be set by an administrator', (string)$response->getContent());
    }

    private ZoneRepositoryInterface&MockObject $zones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
    }

    private function call(string $method, string $kind): object
    {
        $zones = $this->zones;
        $zones->method('zoneExists')->willReturn(true);
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditZoneMeta')->willReturn(true);
        $permission->method('userHasPermission')->willReturn(false);

        $controller = (new ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'authenticatedUserId', 5);
        $this->setProperty($controller, 'pathParameters', ['id' => '1', 'kind' => $kind]);
        $this->setProperty($controller, 'zoneRepository', $zones);
        $this->setProperty($controller, 'apiPermissionService', $permission);
        $this->setProperty($controller, 'request', Request::create('/', 'PUT', [], [], [], [], '{"values":["1"]}'));

        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
