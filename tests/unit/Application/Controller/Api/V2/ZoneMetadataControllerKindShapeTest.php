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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A look-alike of an operator-only kind is refused before the exact-match operator-only check.
 */
#[CoversClass(ZoneMetadataController::class)]
class ZoneMetadataControllerKindShapeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function lookAlikeKinds(): array
    {
        return [
            'accent' => ["ENABL\u{C9}-LUA-RECORDS"],
            'zero width space' => ["ENABLE-LUA-RECORDS\u{200B}"],
            'dotted I' => ["LUA-AXFR-SCR\u{130}PT"],
            'space' => ['ENABLE LUA-RECORDS'],
        ];
    }

    #[DataProvider('lookAlikeKinds')]
    public function testPutRefusesAKindThatIsNotPlainAscii(string $kind): void
    {
        $this->assertSame(400, $this->call('updateMetadataKind', $kind)->getStatusCode());
    }

    #[DataProvider('lookAlikeKinds')]
    public function testDeleteRefusesAKindThatIsNotPlainAscii(string $kind): void
    {
        // A per-kind delete is matched by the collation and would remove the real operator-only row
        $this->assertSame(400, $this->call('deleteMetadataKind', $kind)->getStatusCode());
    }

    public function testTheRealOperatorOnlyKindStillGetsTheOperatorRefusal(): void
    {
        $this->assertSame(403, $this->call('updateMetadataKind', 'ENABLE-LUA-RECORDS')->getStatusCode());
    }

    private function call(string $method, string $kind): JsonResponse
    {
        $controller = (new \ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $zones->expects($this->never())->method('replaceDomainMetadata');

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);

        (new ReflectionProperty(ZoneMetadataController::class, 'zoneRepository'))->setValue($controller, $zones);
        (new ReflectionProperty(ZoneMetadataController::class, 'apiPermissionService'))->setValue($controller, $permissions);
        (new ReflectionProperty(PublicApiController::class, 'pathParameters'))->setValue($controller, ['id' => '1', 'kind' => $kind]);

        return (new ReflectionMethod(ZoneMetadataController::class, $method))->invoke($controller);
    }
}
